package main

import (
	"fmt"
	"math/rand"
)

// Languages for code-poem prompts, weighted toward classics
var codeLangs = []string{
	"BASIC", "Pascal", "C", "COBOL", "Fortran",
	"JavaScript", "Python", "Lua", "Shell script", "SQL",
}

// themeBank maps behavioral labels to thematic directions for the LLM.
// Each behavior gets 3 lenses — one is chosen at random per poem.
var themeBank = map[string][]string{
	"hectic": {
		"Write about urgency — a moth circling a flame, drawn by light it cannot understand.",
		"Write about a storm that visits every window in the street, rattling each one only once.",
		"Write about haste — someone flipping through a book so fast the pages blur into wind.",
	},
	"methodical": {
		"Write about cartography — mapping rooms in a house that has no floor plan.",
		"Write about taxonomy — a collector labeling empty jars, each one carefully cataloged.",
		"Write about patience with a system — testing every key on a ring, one by one, unhurried.",
	},
	"patient": {
		"Write about tides — water that returns to the same shore, always changed, always the same.",
		"Write about growth rings in a tree — each visit a thin line in the wood.",
		"Write about pilgrimage — a traveler who keeps returning to a shrine that was never there.",
	},
	"relentless": {
		"Write about erosion — water that wears a canyon by never stopping.",
		"Write about waves against a cliff — the same question asked a thousand times.",
		"Write about obsession — a bird that builds its nest from the same single thread.",
	},
	"ghostly": {
		"Write about a shooting star — brilliant for a moment, then only memory.",
		"Write about a single footprint on a beach, already filling with water.",
		"Write about passing ships — two lights that cross in darkness and never meet again.",
	},
	"curious": {
		"Write about a garden — someone who wanders between the beds, touching every leaf.",
		"Write about questions without answers — a child asking 'why' to the sky.",
		"Write about wandering — no map, no destination, just the pleasure of looking.",
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
Write only the poem, no titles, no explanations.`

// buildPreamble returns the base preamble, optionally followed by a thematic
// direction drawn from the behavior's theme bank.
func buildPreamble(behavior string) string {
	themes, ok := themeBank[behavior]
	if !ok || len(themes) == 0 {
		return preamble
	}
	theme := themes[rand.Intn(len(themes))]
	return preamble + "\n\n" + theme
}

// contextBlock formats the visit details (path, location, visit count).
func contextBlock(v visit) string {
	s := fmt.Sprintf("- Path: %s\n%s", v.Path, locationLine(v))
	if vc := visitCountLine(v); vc != "" {
		s += "\n" + vc
	}
	return s
}

func promptGenericScan(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A visitor"
	if behavior != "" {
		label = fmt.Sprintf("A %s visitor", behavior)
	}
	return fmt.Sprintf(`%s

%s just knocked on this door:
- Method: %s
%s

Write a short poem (4-8 lines) about this visit.`, pre, label, v.Method, contextBlock(v))
}

func promptWordPress(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just probed for WordPress:
%s

Write a haiku (5-7-5 syllables, three lines).`, pre, label, contextBlock(v))
}

func promptEnvFile(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just tried to read an environment file:
%s

Write a short poem (4-6 lines) about seeking secrets that don't exist.`, pre, label, contextBlock(v))
}

func promptAdminPanel(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just looked for an admin panel:
%s

Write a short poem (4-6 lines) about control and the illusion of being in charge.`, pre, label, contextBlock(v))
}

func promptPathTraversal(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just attempted a path traversal attack:
%s

Write a short poem (4-6 lines).`, pre, label, contextBlock(v))
}

func promptSQLi(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just attempted SQL injection:
%s

Write a short poem (4-6 lines) about asking questions of a database that holds only poems.
You may format it to look like SQL output if you wish.`, pre, label, contextBlock(v))
}

func promptAPI(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just probed for API endpoints:
%s

Write a short poem (4-6 lines) about requesting data from a server that only serves meaning.`, pre, label, contextBlock(v))
}

func promptDevTools(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just probed for developer tools or debug endpoints:
%s

Write a short poem (4-6 lines) about debugging existence.`, pre, label, contextBlock(v))
}

func promptConfigProbe(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just probed for configuration files:
%s

Write a short poem (4-6 lines) about configuration — the settings we choose, the defaults we accept.`, pre, label, contextBlock(v))
}

func promptCredential(v visit, behavior string) string {
	pre := buildPreamble(behavior)
	label := "A scanner"
	if behavior != "" {
		label = fmt.Sprintf("A %s scanner", behavior)
	}
	return fmt.Sprintf(`%s

%s just submitted login credentials to a form that leads nowhere:
%s

Write a short poem (4-6 lines) about identity and authentication in a world of machines.`, pre, label, contextBlock(v))
}

func promptCodePoem(v visit) string {
	lang := codeLangs[rand.Intn(len(codeLangs))]
	return fmt.Sprintf(`You are the Honeypoet — a server that turns internet scanner traffic into art.
Code is poetry. Write a tiny, poetic %s program (8-15 lines) inspired by this visit:
- Path: %s
- Method: %s
%s

The program should be whimsical and reflective — variable names like "seeker", "door", "echo".
It should read like verse. Comments are part of the poem.
Write only the code, no explanations.`, lang, v.Path, v.Method, locationLine(v))
}
