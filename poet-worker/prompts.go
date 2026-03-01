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

	switch v.AttackCategory {
	case "wordpress":
		return promptWordPress(v, behavior), responseTypeFor(v.AttackCategory)
	case "env_file":
		return promptEnvFile(v, behavior), responseTypeFor(v.AttackCategory)
	case "admin_panel":
		return promptAdminPanel(v, behavior), responseTypeFor(v.AttackCategory)
	case "path_traversal":
		return promptPathTraversal(v, behavior), responseTypeFor(v.AttackCategory)
	case "sqli_probe":
		return promptSQLi(v, behavior), responseTypeFor(v.AttackCategory)
	case "api_probe":
		return promptAPI(v, behavior), responseTypeFor(v.AttackCategory)
	case "dev_tools":
		return promptDevTools(v, behavior), responseTypeFor(v.AttackCategory)
	case "config_probe":
		return promptConfigProbe(v, behavior), responseTypeFor(v.AttackCategory)
	case "credential_submit":
		return promptCredential(v, behavior), responseTypeFor(v.AttackCategory)
	default:
		return promptGenericScan(v, behavior), responseTypeFor(v.AttackCategory)
	}
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
