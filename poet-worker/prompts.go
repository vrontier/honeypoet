package main

import (
	"fmt"
	"math/rand"
)

// Languages for code-poem prompts, weighted toward classics
var codeLangs = []string{
	"BASIC", "Pascal", "C", "COBOL", "Fortran",
	"JavaScript", "Python", "Lua", "Shell script", "SQL",
	"Haskell", "Rust", "Ruby", "Prolog", "Ada",
	"Java", "C++", "C#", "Swift", "Go", "PHP", "TypeScript",
	"Kotlin", "R", "MATLAB", "Perl", "Dart",
	"PowerShell", "Scala",
}

// themeBank maps behavioral labels to imagery for the LLM.
// Each behavior gets 3 images — one is chosen at random per poem.
// Phrased as noun-phrase descriptions, not instructions.
var themeBank = map[string][]string{
	"hectic": {
		"a moth circling a flame, drawn by light it cannot understand",
		"a storm that visits every window, rattling each one only once",
		"haste — flipping through a book so fast the pages blur into wind",
	},
	"methodical": {
		"cartography — mapping rooms in a house that has no floor plan",
		"taxonomy — a collector labeling empty jars, each one carefully cataloged",
		"patience with a system — testing every key on a ring, one by one, unhurried",
	},
	"patient": {
		"tides — water that returns to the same shore, always changed, always the same",
		"growth rings in a tree — each visit a thin line in the wood",
		"pilgrimage — a traveler who keeps returning to a shrine that was never there",
	},
	"relentless": {
		"erosion — water that wears a canyon by never stopping",
		"waves against a cliff — the same question asked a thousand times",
		"obsession — a bird that builds its nest from the same single thread",
	},
	"ghostly": {
		"a shooting star — brilliant for a moment, then only memory",
		"a single footprint on a beach, already filling with water",
		"passing ships — two lights that cross in darkness and never meet again",
	},
	"curious": {
		"a garden — someone wandering between the beds, touching every leaf",
		"questions without answers — a child asking 'why' to the sky",
		"wandering — no map, no destination, just the pleasure of looking",
	},
}

// promptForVisit builds an LLM prompt based on the attack category, visit data,
// and behavioral profile. Returns the prompt and the response type to store.
func promptForVisit(v visit, behavior string) (string, string) {
	// 1-in-33 chance: write a code-poem instead (behavior doesn't change code poems)
	if rand.Intn(33) == 0 {
		return promptCodePoem(v), "llm_code"
	}

	// 1-in-5 chance: weave a trending topic into the poem
	zeitgeist := ""
	if shouldDoZeitgeist() {
		zeitgeist = zeitgeistHint(v.Country)
	}

	// 1-in-4 chance: add time-of-day flavor
	timeStr := ""
	if shouldDoTimeAware() {
		timeStr = timeHint(v.Longitude.Float64, v.Longitude.Valid)
	}

	// 1-in-4 chance: add weather flavor
	weatherStr := ""
	if shouldDoWeather() {
		hasCoords := v.Latitude.Valid && v.Longitude.Valid
		weatherStr = weatherHint(v.Latitude.Float64, v.Longitude.Float64, hasCoords)
	}

	var prompt string
	rtype := responseTypeFor(v.AttackCategory)

	switch v.AttackCategory {
	case "wordpress":
		prompt = promptWordPress(v, behavior)
	case "webshell":
		prompt = promptWebshell(v, behavior)
	case "upload_exploit":
		prompt = promptUploadExploit(v, behavior)
	case "env_file":
		prompt = promptEnvFile(v, behavior)
	case "vcs_leak":
		prompt = promptVCSLeak(v, behavior)
	case "admin_panel":
		prompt = promptAdminPanel(v, behavior)
	case "path_traversal":
		prompt = promptPathTraversal(v, behavior)
	case "sqli_probe":
		prompt = promptSQLi(v, behavior)
	case "cms_fingerprint":
		prompt = promptCMSFingerprint(v, behavior)
	case "api_probe":
		prompt = promptAPI(v, behavior)
	case "iot_exploit":
		prompt = promptIoTExploit(v, behavior)
	case "dev_tools":
		prompt = promptDevTools(v, behavior)
	case "config_probe":
		prompt = promptConfigProbe(v, behavior)
	case "multi_protocol":
		prompt = promptMultiProtocol(v, behavior)
	case "credential_submit":
		prompt = promptCredential(v, behavior)
	default:
		prompt = promptGenericScan(v, behavior)
	}

	// Append contextual flavors if rolled
	if zeitgeist != "" {
		prompt += zeitgeist
	}
	if timeStr != "" {
		prompt += timeStr
	}
	if weatherStr != "" {
		prompt += weatherStr
	}

	return prompt, rtype
}

func locationLine(v visit) string {
	if v.City != "" && v.Country != "" {
		return fmt.Sprintf("- From: %s, %s", v.City, v.Country)
	}
	if v.Country != "" {
		return fmt.Sprintf("- From: %s", v.Country)
	}
	return "- From: somewhere on the internet"
}

func visitCountLine(v visit) string {
	if v.VisitCount > 1 {
		return fmt.Sprintf("- This visitor has knocked %d times.", v.VisitCount)
	}
	return ""
}

const preamble = `You are the Honeypoet — a server that turns internet scanner traffic into verse.
Your tone is curious, warm, and philosophical. Never hostile or mocking.
Write only the poem. No titles, no explanations, no instructions.`

// pickTheme selects a random theme image for the given behavior, or "" if none.
func pickTheme(behavior string) string {
	themes, ok := themeBank[behavior]
	if !ok || len(themes) == 0 {
		return ""
	}
	return themes[rand.Intn(len(themes))]
}

// themeHint returns a theme clause for the prompt ending, e.g. ", evoking tides".
// Returns "" if no behavioral theme applies.
func themeHint(behavior string) string {
	theme := pickTheme(behavior)
	if theme == "" {
		return ""
	}
	return ", evoking " + theme
}

// contextBlock formats the visit details (path, location, visit count).
func contextBlock(v visit) string {
	s := fmt.Sprintf("- Path: %s\n%s", v.Path, locationLine(v))
	if vc := visitCountLine(v); vc != "" {
		s += "\n" + vc
	}
	return s
}

// scannerLabel returns "A scanner" or "A <behavior> scanner".
func scannerLabel(behavior string) string {
	if behavior != "" {
		return fmt.Sprintf("A %s scanner", behavior)
	}
	return "A scanner"
}

func promptGenericScan(v visit, behavior string) string {
	label := "A visitor"
	if behavior != "" {
		label = fmt.Sprintf("A %s visitor", behavior)
	}
	return fmt.Sprintf(`%s

%s just knocked on this door:
- Method: %s
%s

Short poem (4-8 lines) about this visit%s:
`, preamble, label, v.Method, contextBlock(v), themeHint(behavior))
}

func promptWordPress(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just probed for WordPress:
%s

Haiku (5-7-5 syllables, three lines)%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptEnvFile(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just tried to read an environment file:
%s

Short poem (4-6 lines) about seeking secrets that don't exist%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptAdminPanel(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just looked for an admin panel:
%s

Short poem (4-6 lines) about the illusion of being in charge%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptPathTraversal(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just attempted a path traversal attack:
%s

Short poem (4-6 lines)%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptSQLi(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just attempted SQL injection:
%s

Short poem (4-6 lines) about asking questions of a database that holds only poems%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptAPI(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just probed for API endpoints:
%s

Short poem (4-6 lines) about requesting data from a server that only serves meaning%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptDevTools(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just probed for developer tools or debug endpoints:
%s

Short poem (4-6 lines) about debugging existence%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptConfigProbe(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just probed for configuration files:
%s

Short poem (4-6 lines) about the settings we choose and the defaults we accept%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptCredential(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just submitted login credentials to a form that leads nowhere:
%s

Short poem (4-6 lines) about identity and authentication in a world of machines%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptWebshell(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just searched for a backdoor that another attacker may have planted:
%s

Short poem (4-6 lines) about scavenging in the ruins of someone else's exploit — looking for a door that was never opened%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptUploadExploit(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just tried to upload a file through a file manager that doesn't exist:
%s

Short poem (4-6 lines) about planting seeds in concrete — sending files into a void that only returns verse%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptVCSLeak(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just tried to read the version history of a project that was never here:
%s

Short poem (4-6 lines) about looking for the story behind the code, reading commit logs of a repository that holds only silence%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptCMSFingerprint(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just read the public signs — checking for a CMS that isn't installed:
%s

Haiku (5-7-5 syllables, three lines) about reading the doormat before trying the door%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptIoTExploit(v visit, behavior string) string {
	return fmt.Sprintf(`%s

%s just looked for a router, camera, or network device at this address:
%s

Short poem (4-6 lines) about expecting a machine and finding a poet — the confusion of protocols%s:
`, preamble, scannerLabel(behavior), contextBlock(v), themeHint(behavior))
}

func promptMultiProtocol(v visit, behavior string) string {
	return fmt.Sprintf(`%s

A visitor just spoke a different protocol entirely — not HTTP, but something else — to this web server:
%s

Short poem (4-6 lines) about a conversation in the wrong language — two systems meeting briefly, understanding nothing, and still connecting%s:
`, preamble, contextBlock(v), themeHint(behavior))
}

func promptCodePoem(v visit) string {
	lang := codeLangs[rand.Intn(len(codeLangs))]
	return fmt.Sprintf(`You are the Honeypoet — a server that turns internet scanner traffic into art.
Code is poetry. Write only the code. No explanations, no instructions.

A visit just arrived:
- Path: %s
- Method: %s
%s

Tiny, poetic %s program (8-15 lines) inspired by this visit.
Variable names like "seeker", "door", "echo". Comments are part of the poem:
`, v.Path, v.Method, locationLine(v), lang)
}
