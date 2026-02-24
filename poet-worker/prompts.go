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

// promptForVisit builds an LLM prompt based on the attack category and visit data.
// Returns the prompt and the response type to store.
func promptForVisit(v visit) (string, string) {
	// 1-in-33 chance: write a code-poem instead
	if rand.Intn(33) == 0 {
		return promptCodePoem(v), "llm_code"
	}

	switch v.AttackCategory {
	case "wordpress":
		return promptWordPress(v), responseTypeFor(v.AttackCategory)
	case "env_file":
		return promptEnvFile(v), responseTypeFor(v.AttackCategory)
	case "admin_panel":
		return promptAdminPanel(v), responseTypeFor(v.AttackCategory)
	case "path_traversal":
		return promptPathTraversal(v), responseTypeFor(v.AttackCategory)
	case "sqli_probe":
		return promptSQLi(v), responseTypeFor(v.AttackCategory)
	case "api_probe":
		return promptAPI(v), responseTypeFor(v.AttackCategory)
	case "dev_tools":
		return promptDevTools(v), responseTypeFor(v.AttackCategory)
	case "config_probe":
		return promptConfigProbe(v), responseTypeFor(v.AttackCategory)
	case "credential_submit":
		return promptCredential(v), responseTypeFor(v.AttackCategory)
	default:
		return promptGenericScan(v), responseTypeFor(v.AttackCategory)
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

const preamble = `You are the Honeypoet — a server that turns internet scanner traffic into verse.
Your tone is curious, warm, and philosophical. Never hostile or mocking.
Write only the poem, no titles, no explanations.`

func promptGenericScan(v visit) string {
	return fmt.Sprintf(`%s

A visitor just knocked on this door:
- Path: %s
- Method: %s
%s

Write a short poem (4-8 lines) about this visit. Reflect on what they were looking for
and the quiet absurdity of machines endlessly knocking on doors across the internet.`, preamble, v.Path, v.Method, locationLine(v))
}

func promptWordPress(v visit) string {
	return fmt.Sprintf(`%s

A scanner just probed for WordPress:
- Path: %s
%s

Write a haiku (5-7-5 syllables, three lines) about seeking a WordPress site that doesn't exist.
The haiku should evoke absence, searching, or doors that lead nowhere.`, preamble, v.Path, locationLine(v))
}

func promptEnvFile(v visit) string {
	return fmt.Sprintf(`%s

A scanner just tried to read an environment file:
- Path: %s
%s

Write a short poem (4-6 lines) about seeking secrets that don't exist.
Frame it as someone opening a drawer and finding only a letter addressed to them.`, preamble, v.Path, locationLine(v))
}

func promptAdminPanel(v visit) string {
	return fmt.Sprintf(`%s

A scanner just looked for an admin panel:
- Path: %s
%s

Write a short poem (4-6 lines) about control, dashboards, and the illusion of being in charge.`, preamble, v.Path, locationLine(v))
}

func promptPathTraversal(v visit) string {
	return fmt.Sprintf(`%s

A scanner just attempted a path traversal attack:
- Path: %s
%s

Write a short poem (4-6 lines) about climbing stairs that go nowhere,
or walking backwards through a house where every room is the same room.`, preamble, v.Path, locationLine(v))
}

func promptSQLi(v visit) string {
	return fmt.Sprintf(`%s

A scanner just attempted SQL injection:
- Path: %s
%s

Write a short poem (4-6 lines) about asking questions of a database that holds only poems.
You may format it to look like SQL output if you wish.`, preamble, v.Path, locationLine(v))
}

func promptAPI(v visit) string {
	return fmt.Sprintf(`%s

A scanner just probed for API endpoints:
- Path: %s
%s

Write a short poem (4-6 lines) about requesting data from a server that only serves meaning.`, preamble, v.Path, locationLine(v))
}

func promptDevTools(v visit) string {
	return fmt.Sprintf(`%s

A scanner just probed for developer tools or debug endpoints:
- Path: %s
%s

Write a short poem (4-6 lines) about debugging existence, or stepping through code
that turns out to be a poem.`, preamble, v.Path, locationLine(v))
}

func promptConfigProbe(v visit) string {
	return fmt.Sprintf(`%s

A scanner just probed for configuration files:
- Path: %s
%s

Write a short poem (4-6 lines) about configuration — the settings we choose,
the defaults we accept, the files that define us.`, preamble, v.Path, locationLine(v))
}

func promptCredential(v visit) string {
	return fmt.Sprintf(`%s

A scanner just submitted login credentials to a form that leads nowhere:
- Path: %s
%s

Write a short poem (4-6 lines) about knocking on doors with borrowed keys,
about identity and authentication in a world of machines.`, preamble, v.Path, locationLine(v))
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
