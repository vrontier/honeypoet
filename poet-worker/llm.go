package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"regexp"
	"strings"
	"time"
)

var ipPattern = regexp.MustCompile(`\[?\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\]?`)

// instructionSuffixPattern catches mid-line instruction bleed where Phi weaves
// prompt-style directives into poem lines: "shadows stretch — let this color
// the mood subtly." Strip the suffix, keep the poem. Uses (?m) so $ matches
// end of each line, not just end of string.
var instructionSuffixPattern = regexp.MustCompile(`(?im)\s*(?:\x{2014}|\x{2013}|-){1,3}\s*let this\b.*$`)

// parenInstructionPattern matches parenthetical instructions at the start of
// a line: "(Avoid direct references to the weather.)" — may be followed by
// more text on the same line.
var parenInstructionPattern = regexp.MustCompile(`(?i)^\((?:avoid|do not|don't|no |the poem|include|incorporate|mention|remember|keep|ensure|make)\b[^)]*\)\s*`)

// httpHeaderPattern matches HTTP response header lines that Phi sometimes
// fabricates: "- Status: 302 Found", "- Location: /blog/...", "ETag: W/..."
var httpHeaderPattern = regexp.MustCompile(`(?i)^-?\s*(?:status:\s*\d|location:\s*/|etag:|x-[a-z]|date:\s*\w{3},|content-type:|cache-control:|last-modified:|server:\s)`)

// formLabels are poetry form names and meta-content markers. When these appear
// in a section after a blank line, that section (and everything after) is
// truncated — it's the model generating variations, not continuing the poem.
var formLabels = []string{
	"haiku", "tanka", "haibun", "villanelle", "sonnet", "limerick",
	"syllable", "5-7-5",
	"three lines", "five lines",
	"no html", "no css",
}
var jsonBlockPattern = regexp.MustCompile(`(?s)\{[^{}]*\}`)
var markdownBoldPattern = regexp.MustCompile(`\*\*([^*]+)\*\*`)

// llmClient talks to an OpenAI-compatible /v1/chat/completions endpoint.
// Local llama-server applies the model's chat template there, so an instruct
// model (Phi-4-mini) answers the prompt instead of continuing it as a document.
// When apiKey is set (cloud providers), it is sent as a Bearer token.
type llmClient struct {
	baseURL    string
	apiKey     string
	model      string
	httpClient *http.Client
	maxTokens  int
}

func newLLMClient(baseURL, apiKey, model string) *llmClient {
	return &llmClient{
		baseURL: strings.TrimRight(baseURL, "/"),
		apiKey:  apiKey,
		model:   model,
		httpClient: &http.Client{
			Timeout: 120 * time.Second, // local CPU inference can be slow
		},
		maxTokens: 150,
	}
}

// chatMessage is a single message in a chat completions request/response.
type chatMessage struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

// chatRequest is the OpenAI /v1/chat/completions request body.
type chatRequest struct {
	Model       string        `json:"model"`
	Messages    []chatMessage `json:"messages"`
	MaxTokens   int           `json:"max_tokens"`
	Temperature float64       `json:"temperature"`
	Stop        []string      `json:"stop,omitempty"`
}

// chatResponse is the OpenAI /v1/chat/completions response body.
type chatResponse struct {
	Choices []struct {
		Message chatMessage `json:"message"`
	} `json:"choices"`
}

// Generation settings per output kind. Verse stops at the first blank line,
// which keeps the model from appending a second variation or commentary.
// Code-poems need blank lines (between imports and functions), so they only
// stop on "Note:" and get more room; cleanCode keeps the fenced block.
var (
	verseStops = []string{"\n\n", "\nNote:"}
	codeStops  = []string{"\nNote:"}
)

const codeMaxTokens = 300

// generate sends a prompt to the LLM and returns the cleaned poem. respType
// is the response type from promptForVisit; "llm_code" selects code-poem
// settings and cleaning.
//
// It always uses /v1/chat/completions. The raw /v1/completions endpoint was
// dropped (Oct 2026): without a chat template Phi-4-mini continued the prompt
// document — instruction lines, invented "- IP:" metadata — instead of
// writing a poem, which caused most of the ~20% LLM failure rate.
func (c *llmClient) generate(prompt, respType string) (string, error) {
	if respType == "llm_code" {
		raw, err := c.chat(prompt, codeMaxTokens, codeStops)
		if err != nil {
			return "", err
		}
		return cleanCode(raw), nil
	}
	raw, err := c.chat(prompt, c.maxTokens, verseStops)
	if err != nil {
		return "", err
	}
	return cleanPoem(raw, prompt), nil
}

// chat sends a single user message to /v1/chat/completions and returns the
// raw reply. The Authorization header is only sent when an API key is set.
func (c *llmClient) chat(prompt string, maxTokens int, stops []string) (string, error) {
	reqBody := chatRequest{
		Model:       c.model,
		Messages:    []chatMessage{{Role: "user", Content: prompt}},
		MaxTokens:   maxTokens,
		Temperature: 0.8,
		Stop:        stops,
	}

	body, err := json.Marshal(reqBody)
	if err != nil {
		return "", fmt.Errorf("marshal request: %w", err)
	}

	req, err := http.NewRequest("POST", c.baseURL+"/v1/chat/completions", bytes.NewReader(body))
	if err != nil {
		return "", fmt.Errorf("create request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	if c.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("LLM request failed: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		respBody, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return "", fmt.Errorf("LLM returned %d: %s", resp.StatusCode, string(respBody))
	}

	var result chatResponse
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return "", fmt.Errorf("decode response: %w", err)
	}

	if len(result.Choices) == 0 || strings.TrimSpace(result.Choices[0].Message.Content) == "" {
		return "", fmt.Errorf("LLM returned empty response")
	}

	return result.Choices[0].Message.Content, nil
}

// codeFencePattern captures the body of the first fenced code block. A
// missing closing fence (reply cut off by max_tokens) takes the rest.
var codeFencePattern = regexp.MustCompile("(?s)```[^\n]*\n(.*?)(?:```|$)")

// cleanCode extracts a code-poem from the reply. Unlike cleanPoem it leaves
// lines alone: "use strict;", closing braces and blank lines are the poem.
func cleanCode(raw string) string {
	s := strings.TrimSpace(raw)
	if m := codeFencePattern.FindStringSubmatch(s); m != nil {
		s = m[1]
	}
	s = strings.ReplaceAll(s, "```", "")
	s = ipPattern.ReplaceAllString(s, "")
	s = strings.TrimSpace(s)
	if !hasASCIILetter(s) || len(s) < 15 {
		return ""
	}
	return s
}

// minEchoCoverage is the percentage of a prompt line a response line must
// cover to count as an echo of it.
const minEchoCoverage = 75

// promptPhrases extracts normalized phrases (>= 20 chars) from the prompt
// for echo detection. Short fragments are skipped to avoid false positives.
func promptPhrases(prompt string) []string {
	lower := strings.ToLower(prompt)
	var phrases []string
	for _, line := range strings.Split(lower, "\n") {
		line = strings.TrimSpace(line)
		// Strip leading "- " for context lines
		line = strings.TrimPrefix(line, "- ")
		line = strings.TrimSpace(line)
		if len(line) >= 20 {
			phrases = append(phrases, line)
		}
	}
	return phrases
}

// isPromptEcho returns true if a response line echoes content from the prompt.
// A line that is only a fragment of a prompt line counts as an echo when it
// covers most of that line (minEchoCoverage). Short fragments are normal
// poem content: the city name from "- From: Frankfurt am Main, DE", or the
// theme image from the end of the prompt.
func isPromptEcho(line string, phrases []string) bool {
	lower := strings.ToLower(strings.TrimSpace(line))
	// Strip leading list markers for comparison
	check := strings.TrimPrefix(lower, "- ")
	check = strings.TrimPrefix(check, "* ")
	check = strings.TrimSpace(check)
	if len(check) < 10 {
		return false
	}
	for _, phrase := range phrases {
		// Response line is most of a prompt line
		if strings.Contains(phrase, check) && len(check)*100 >= len(phrase)*minEchoCoverage {
			return true
		}
		// Prompt phrase appears in the response line
		if strings.Contains(check, phrase) {
			return true
		}
	}
	return false
}

// cleanPoem strips LLM noise — the model (Granite, then Phi) sometimes echoes
// instructions, emits JSON, dumps HTML/CSS, spams emoji, or adds
// meta-commentary around the verse.
// The prompt parameter enables echo detection: response lines that match prompt
// content are stripped. Pass "" to skip prompt-aware cleaning (e.g. for cleanup).
func cleanPoem(raw string, prompt string) string {
	s := strings.TrimSpace(raw)
	if s == "" {
		return ""
	}

	// Strip backtick fences early (before line-level processing)
	s = strings.ReplaceAll(s, "```", "")
	s = strings.ReplaceAll(s, "`", "")

	// Strip blockquote markers: "> line" → "line"
	if strings.HasPrefix(s, "> ") || strings.Contains(s, "\n> ") {
		lines := strings.Split(s, "\n")
		for i, l := range lines {
			if strings.HasPrefix(l, "> ") {
				lines[i] = l[2:]
			}
		}
		s = strings.Join(lines, "\n")
	}

	// If the model separated content with --- (multiple sections), take the
	// last segment that contains actual poem content. The model sometimes
	// generates self-instructions or meta-commentary in separate sections.
	if strings.Contains(s, "\n---") {
		segments := strings.Split(s, "\n---")
		var best string
		for _, seg := range segments {
			seg = strings.TrimSpace(seg)
			if seg == "" {
				continue
			}
			// Strip "Answer:" prefix — the model sometimes writes this before poems
			if len(seg) > 7 && strings.HasPrefix(strings.ToLower(seg), "answer:") {
				seg = strings.TrimSpace(seg[7:])
			}
			// Skip segments that are just meta-commentary
			if strings.HasPrefix(strings.ToLower(seg), "note:") {
				continue
			}
			if hasASCIILetter(seg) {
				best = seg
			}
		}
		if best != "" {
			s = best
		}
	}

	// If the model echoed back the prompt context ("A visitor just knocked"),
	// try to find where the actual poem starts after it.
	for _, marker := range []string{"A visitor just", "A scanner just"} {
		if idx := strings.Index(s, marker); idx >= 0 {
			rest := s[idx:]
			if nl := strings.Index(rest, "\n\n"); nl >= 0 {
				s = strings.TrimSpace(rest[nl+2:])
			}
		}
	}

	// Remove JSON blocks like {"path": "/foo", ...}
	s = jsonBlockPattern.ReplaceAllString(s, "")

	// Strip markdown bold markers: **text** → text
	s = markdownBoldPattern.ReplaceAllString(s, "$1")

	// Strip mid-line instruction bleed: "shadows stretch — let this color
	// the mood subtly" → "shadows stretch". Phi weaves prompt-style
	// directives into poem lines instead of following them.
	s = instructionSuffixPattern.ReplaceAllString(s, "")

	// Strip parenthetical instructions from start of lines:
	// "(Avoid direct references to the weather.) The poem should..." →
	// "The poem should..." (which then gets caught by noise prefixes)
	s = parenInstructionPattern.ReplaceAllString(s, "")

	// Build prompt phrases for echo detection
	phrases := promptPhrases(prompt)

	// Process line-by-line: strip noise from both start and end
	lines := strings.Split(s, "\n")

	isNoise := func(line string) bool {
		return isNoiseLine(line) || isPromptEcho(line, phrases)
	}

	start := 0
	for start < len(lines) {
		if isNoise(lines[start]) {
			start++
			continue
		}
		break
	}

	// Truncate at the first prompt-echo line after the poem starts.
	// This catches prompt repetition in the middle of the response.
	truncAt := len(lines)
	if len(phrases) > 0 {
		for i := start; i < len(lines); i++ {
			if isPromptEcho(lines[i], phrases) {
				truncAt = i
				break
			}
		}
	}

	end := truncAt
	for end > start {
		if isNoise(lines[end-1]) {
			end--
			continue
		}
		break
	}

	if start >= end {
		return ""
	}

	s = strings.TrimSpace(strings.Join(lines[start:end], "\n"))

	// If the cleaned result has blank-line-separated sections, check each
	// section after the first for form labels / meta content. Truncate at
	// the first section that looks like meta (poetry form names, syllable
	// specs, etc.) while preserving legitimate multi-stanza poems.
	if strings.Contains(s, "\n\n") {
		sections := strings.Split(s, "\n\n")
		keep := 1
		for i := 1; i < len(sections); i++ {
			lower := strings.ToLower(sections[i])
			meta := false
			for _, label := range formLabels {
				if strings.Contains(lower, label) {
					meta = true
					break
				}
			}
			if meta {
				break
			}
			keep = i + 1
		}
		s = strings.TrimSpace(strings.Join(sections[:keep], "\n\n"))
	}

	// Deduplicate repetition loops: Phi sometimes gets stuck repeating
	// the same line or stanza. If any line appears 3+ times, collapse
	// to a single occurrence.
	s = deduplicateLines(s)

	// Strip IP addresses
	s = ipPattern.ReplaceAllString(s, "")
	s = strings.TrimSpace(s)

	// Final quality checks
	if !hasASCIILetter(s) {
		return ""
	}
	// Too short to be a real poem (catches single-word junk like "Honeypoet")
	if len(s) < 15 {
		return ""
	}

	return s
}

// isNoiseLine returns true if a line looks like echoed instructions, JSON,
// HTML/CSS, emoji spam, or other non-poem content.
func isNoiseLine(raw string) bool {
	line := strings.TrimSpace(raw)
	lower := strings.ToLower(line)

	// Blank lines
	if line == "" {
		return true
	}
	// Separator lines (---, ===)
	if strings.Trim(line, "-") == "" || strings.Trim(line, "=") == "" || strings.Trim(line, "* ") == "" {
		return true
	}
	// Lines with no ASCII letters (emoji-only, punctuation-only)
	if !hasASCIILetter(line) {
		return true
	}
	// HTTP response headers (Phi fabricates these)
	if httpHeaderPattern.MatchString(line) {
		return true
	}
	// Fence language labels on their own
	switch lower {
	case "json", "css", "html", "http", "javascript", "python", "sql",
		"bash", "text", "xml", "yaml", "toml", "plaintext",
		"pascal", "fortran", "basic",
		"java", "c++", "c#", "ruby", "swift", "go", "php", "typescript",
		"kotlin", "rust", "r", "matlab", "perl", "dart", "shell",
		"powershell", "scala", "haskell", "cobol", "assembly",
		"makefile", "prolog", "ada", "lua":
		return true
	}
	// JSON structural lines
	if isJSONLine(line) {
		return true
	}
	// HTML tag lines
	if strings.HasPrefix(line, "<") && strings.HasSuffix(line, ">") {
		return true
	}
	// CSS rule lines
	if (strings.HasSuffix(line, "{") || strings.HasSuffix(line, "}") || strings.HasSuffix(line, ";")) &&
		(strings.Contains(line, ":") || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ".")) {
		return true
	}
	// URL lines
	if strings.HasPrefix(lower, "http://") || strings.HasPrefix(lower, "https://") {
		return true
	}
	// Lines referencing "Honeypoet" in instruction context
	if strings.Contains(lower, "honeypoet") &&
		(strings.Contains(lower, "you are") || strings.Contains(lower, "rules") ||
			strings.Contains(lower, "remember") || strings.Contains(lower, "server that") ||
			strings.Contains(lower, "poem")) {
		return true
	}
	// "from:" on its own (structured data echo, not "from afar" etc.)
	if lower == "from:" {
		return true
	}
	// "the visit" as meta-commentary, but NOT "the visitor" which is poetic
	if strings.HasPrefix(lower, "the visit") && !strings.HasPrefix(lower, "the visitor") {
		return true
	}
	// Instruction echo prefixes — expanded from observed Granite and Phi outputs
	for _, prefix := range noisePrefixes {
		if strings.HasPrefix(lower, prefix) {
			return true
		}
	}
	// <variable> = "value" patterns
	if strings.Contains(line, "> = \"") && strings.HasPrefix(line, "<") {
		return true
	}
	// Markdown list items that are instruction echoes: "- Do not mention...", "- Avoid..."
	if strings.HasPrefix(lower, "- ") || strings.HasPrefix(lower, "* ") {
		rest := strings.TrimSpace(lower[2:])
		for _, prefix := range noisePrefixes {
			if strings.HasPrefix(rest, prefix) {
				return true
			}
		}
		// Bare URL paths as list items: "- /wp-login.php", "- /admin/setup.php"
		if strings.HasPrefix(rest, "/") && !strings.Contains(rest, " ") {
			return true
		}
	}
	// Bare URL paths on their own: "/wp-login.php", "/admin/config"
	if strings.HasPrefix(lower, "/") && !strings.Contains(lower, " ") {
		return true
	}
	return false
}

// noisePrefixes are lowercased prefixes that indicate echoed instructions
// rather than actual poem content.
var noisePrefixes = []string{
	// Direct instruction echoes
	"use ", "make it", "write ", "here is ", "here's ",
	"guidelines", "instructions", "stay ", "convey ", "conclude ",
	"maintain ", "keep ", "ensure ", "avoid ", "include ",
	"the poem ", "the haiku ", "format ", "do not ", "be sure ",
	"your answer", "your tone", "try ", "consider ", "think ",
	"never hostile", "never mock",
	// Meta-commentary about the visit/poem
	"show compassion", "show empathy", "show some",
	"be gentle", "close with", "answer with", "answer only",
	"and remember", "and if you can", "ends with ",
	"the visit detail", "the visit show", "the visit end",
	"no titles", "no explanation",
	// "remember" followed by instruction markers
	"remember,", "remember:", "remember you",
	// Structured data / HTTP echoes
	"- path:", "- method:", "- from:", "- ip:", "- user", "- host:",
	"path:", "method:", "from:", "host:",
	"get /", "post /", "put /", "delete /", "head /",
	// Title/heading echoes
	"visitor details", "poem:",
	// Self-generated instructions (the model sometimes writes these before poems)
	"each line should", "each line must",
	"no more, no less",
	"constraints:",
	// Trailing meta-commentary
	"note: this", "note: the ",
	// Prompt template echoes
	"- title:", "title:",
	"- lines:", "lines:",
	// Multi-poem generation (the model sometimes generates variations)
	"haibun ", "5.7.5 ",
	// Code-poem instruction prefixes
	"no html", "no jokes", "no commentary",
	"remember to ", "no dark ",
	// The model sometimes labels output with the persona name
	"honeypoet:", "honeypoet writes", "the honeypoet writes",
	"honeypoet's poem", "the honeypoet's poem",
	// Phi instruction echoes
	"your poem:",      // colon variant (existing "your poem " has trailing space)
	"mention ",        // "Mention the city and country"
	"incorporate ",    // "Incorporate the idea that..."
	"start with",      // "Start with:" instructions
	"end with",        // "End with:" instructions
	"no specific",     // "No specific names, no direct references"
	// Structured data / HTTP header echoes
	"page size",       // structured data echo
	"text speed",      // structured data echo
	"last-modified",   // HTTP header echo
	"server:",         // HTTP header echo (colon prevents matching prose "server")
	"question: ",      // Phi self-assignment: "Question: Craft a sestet..."
	"this visitor is ", // Phi echoes "This visitor is new/searching/alone..."
	"this door is ",   // Phi echoes "This door is empty..."
	"don't spell",     // "Don't spell out this much"
	"no more.",        // "No more." as instruction line
	"no names",        // "No names, just a story..."
	"you could say ",  // Phi generating commentary: "You could say this visitor..."
	// Self-generated constraints (the model writes rules for itself before poems)
	"reflect on ", "contrast ",
	"no other ", "the word ",
	"lines of ", "please adhere",
	"no punctuation", "no capitalization",
	"your poem ", "in your ",
	"poem must ", "poem should ",
	"haiku:",
}

// deduplicateLines collapses repetition loops where Phi gets stuck repeating
// the same line. If any non-blank line appears 3+ times, only the first
// occurrence is kept.
func deduplicateLines(s string) string {
	lines := strings.Split(s, "\n")
	// Count occurrences of each non-blank line
	counts := make(map[string]int)
	for _, l := range lines {
		trimmed := strings.TrimSpace(l)
		if trimmed != "" {
			counts[trimmed]++
		}
	}
	// Check if any line is repeated 3+ times
	hasLoop := false
	for _, c := range counts {
		if c >= 3 {
			hasLoop = true
			break
		}
	}
	if !hasLoop {
		return s
	}
	// Keep only first occurrence of repeated lines
	seen := make(map[string]bool)
	var out []string
	for _, l := range lines {
		trimmed := strings.TrimSpace(l)
		if trimmed == "" {
			out = append(out, l)
			continue
		}
		if counts[trimmed] >= 3 && seen[trimmed] {
			continue
		}
		seen[trimmed] = true
		out = append(out, l)
	}
	return strings.Join(out, "\n")
}

// hasASCIILetter returns true if the string contains at least one a-zA-Z.
func hasASCIILetter(s string) bool {
	for _, r := range s {
		if (r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') {
			return true
		}
	}
	return false
}

// isJSONLine returns true if a line looks like JSON structure.
func isJSONLine(line string) bool {
	t := strings.TrimSpace(line)
	if t == "{" || t == "}" || t == "[" || t == "]" || t == "}," || t == "]," {
		return true
	}
	// "key": "value" or "key": number
	if strings.HasPrefix(t, "\"") && strings.Contains(t, "\":") {
		return true
	}
	return false
}
