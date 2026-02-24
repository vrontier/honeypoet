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
var jsonBlockPattern = regexp.MustCompile(`(?s)\{[^{}]*\}`)
var markdownBoldPattern = regexp.MustCompile(`\*\*([^*]+)\*\*`)

// llmClient talks to an OpenAI-compatible API. When apiKey is set, it uses
// the /v1/chat/completions endpoint with Bearer auth (cloud providers).
// Otherwise it uses the legacy /v1/completions endpoint (local llama-server).
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
			Timeout: 120 * time.Second, // Granite can be slow on CPU
		},
		maxTokens: 256,
	}
}

// completionRequest is the OpenAI /v1/completions request body.
type completionRequest struct {
	Prompt      string  `json:"prompt"`
	MaxTokens   int     `json:"max_tokens"`
	Temperature float64 `json:"temperature"`
	Stop        []string `json:"stop,omitempty"`
}

// completionResponse is the OpenAI /v1/completions response body.
type completionResponse struct {
	Choices []struct {
		Text string `json:"text"`
	} `json:"choices"`
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
}

// chatResponse is the OpenAI /v1/chat/completions response body.
type chatResponse struct {
	Choices []struct {
		Message chatMessage `json:"message"`
	} `json:"choices"`
}

// generate sends a prompt to the LLM and returns the generated text.
// When an API key is configured, it uses the chat completions endpoint;
// otherwise it falls back to the legacy completions endpoint.
func (c *llmClient) generate(prompt string) (string, error) {
	if c.apiKey != "" {
		return c.generateChat(prompt)
	}
	return c.generateLegacy(prompt)
}

// generateLegacy uses the /v1/completions endpoint (local llama-server).
func (c *llmClient) generateLegacy(prompt string) (string, error) {
	reqBody := completionRequest{
		Prompt:      prompt,
		MaxTokens:   c.maxTokens,
		Temperature: 0.8,
		Stop:        []string{"\n\n\n"},
	}

	body, err := json.Marshal(reqBody)
	if err != nil {
		return "", fmt.Errorf("marshal request: %w", err)
	}

	req, err := http.NewRequest("POST", c.baseURL+"/v1/completions", bytes.NewReader(body))
	if err != nil {
		return "", fmt.Errorf("create request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("LLM request failed: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		respBody, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return "", fmt.Errorf("LLM returned %d: %s", resp.StatusCode, string(respBody))
	}

	var result completionResponse
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return "", fmt.Errorf("decode response: %w", err)
	}

	if len(result.Choices) == 0 || strings.TrimSpace(result.Choices[0].Text) == "" {
		return "", fmt.Errorf("LLM returned empty response")
	}

	return cleanPoem(result.Choices[0].Text), nil
}

// generateChat uses the /v1/chat/completions endpoint (cloud providers).
func (c *llmClient) generateChat(prompt string) (string, error) {
	reqBody := chatRequest{
		Model:       c.model,
		Messages:    []chatMessage{{Role: "user", Content: prompt}},
		MaxTokens:   c.maxTokens,
		Temperature: 0.8,
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
	req.Header.Set("Authorization", "Bearer "+c.apiKey)

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

	return cleanPoem(result.Choices[0].Message.Content), nil
}

// cleanPoem strips LLM noise — Granite sometimes echoes instructions, emits
// JSON, dumps HTML/CSS, spams emoji, or adds meta-commentary around the verse.
func cleanPoem(raw string) string {
	s := strings.TrimSpace(raw)
	if s == "" {
		return ""
	}

	// Strip backtick fences early (before line-level processing)
	s = strings.ReplaceAll(s, "```", "")
	s = strings.ReplaceAll(s, "`", "")

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

	// Process line-by-line: strip noise from both start and end
	lines := strings.Split(s, "\n")

	start := 0
	for start < len(lines) {
		if isNoiseLine(lines[start]) {
			start++
			continue
		}
		break
	}

	end := len(lines)
	for end > start {
		if isNoiseLine(lines[end-1]) {
			end--
			continue
		}
		break
	}

	if start >= end {
		return ""
	}

	s = strings.TrimSpace(strings.Join(lines[start:end], "\n"))

	// Strip IP addresses
	s = ipPattern.ReplaceAllString(s, "")
	s = strings.TrimSpace(s)

	// Final quality check: reject if no ASCII letters remain
	if !hasASCIILetter(s) {
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
	// Fence language labels on their own
	switch lower {
	case "json", "css", "html", "http", "javascript", "python", "sql",
		"bash", "text", "xml", "yaml", "toml", "plaintext":
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
	// Instruction echo prefixes — expanded from observed Granite outputs
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
	"the visit", "no titles", "no explanation",
	// "remember" followed by instruction markers
	"remember,", "remember:", "remember you",
	// Structured data / HTTP echoes
	"- path:", "- method:", "- from:", "- ip:", "- user", "- host:",
	"path:", "method:", "from:", "host:",
	"get /", "post /", "put /", "delete /", "head /",
	// Title/heading echoes
	"visitor details", "poem:",
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
