package main

import (
	"database/sql"
	"fmt"
	"log"
	"os"
	"runtime"
	"strings"
)

// poolTarget is the number of pre-generated poems per (category, behavior) slot.
const poolTarget = 2

// cpuCeiling is the fraction of CPUs above which we skip pool filling.
const cpuCeiling = 0.6

// poolCategories are attack categories eligible for pool pre-generation.
var poolCategories = []string{
	"wordpress", "webshell", "upload_exploit", "env_file", "vcs_leak",
	"admin_panel", "path_traversal", "sqli_probe", "cms_fingerprint",
	"api_probe", "iot_exploit", "dev_tools", "config_probe",
	"multi_protocol", "credential_submit", "generic_scan",
}

// poolBehaviors are behavioral profiles to generate pool poems for.
var poolBehaviors = []string{
	"hectic", "methodical", "patient", "relentless", "ghostly", "curious",
}

// migratePool creates the poem_pool table if it doesn't exist.
func migratePool(db *sql.DB) {
	_, err := db.Exec(`
		CREATE TABLE IF NOT EXISTS poem_pool (
			id            INTEGER PRIMARY KEY AUTOINCREMENT,
			category      TEXT NOT NULL,
			behavior      TEXT NOT NULL,
			poem          TEXT NOT NULL,
			response_type TEXT NOT NULL,
			created_at    TEXT NOT NULL DEFAULT (datetime('now')),
			used_count    INTEGER NOT NULL DEFAULT 0
		)
	`)
	if err != nil {
		log.Printf("pool migration: %v", err)
		return
	}
	db.Exec(`CREATE INDEX IF NOT EXISTS idx_pool_cat_beh ON poem_pool (category, behavior)`)
}

// shouldUsePool returns true if this visit should draw from the pool
// instead of generating a fresh LLM poem.
func shouldUsePool(v visit) bool {
	// Hectic or relentless bots always use pool (beyond first poem, handled by reuse)
	if v.Behavior == "hectic" || v.Behavior == "relentless" {
		return true
	}
	// High-frequency visitors beyond their first few poems
	if v.VisitCount > 3 {
		return true
	}
	return false
}

// drawFromPool tries to find a pre-generated poem for the given category and behavior.
// Returns the poem and response type, or empty strings if none available.
func drawFromPool(db *sql.DB, category, behavior string) (poem, responseType string, found bool) {
	err := db.QueryRow(`
		SELECT id, poem, response_type FROM poem_pool
		WHERE category = ? AND behavior = ?
		ORDER BY used_count ASC, RANDOM()
		LIMIT 1
	`, category, behavior).Scan(new(int64), &poem, &responseType)
	if err != nil {
		return "", "", false
	}
	// Increment used_count
	db.Exec(`UPDATE poem_pool SET used_count = used_count + 1
		WHERE category = ? AND behavior = ? AND poem = ?`, category, behavior, poem)
	return poem, responseType, true
}

// isQuiet returns true if the system has spare capacity for pool generation.
// Key insight: visitor rate doesn't matter if reuse handles most visits.
// What matters is: is the LLM idle? Check pending work and CPU load.
func isQuiet(db *sql.DB, pending int, batchSize int) bool {
	// Hard ceiling: LLM has real work to do
	if pending > batchSize {
		return false
	}

	// CPU headroom — is the LLM already busy?
	load := cpuLoad()
	cpus := float64(runtime.NumCPU())
	if load > cpus*cpuCeiling {
		return false
	}

	return true
}

// cpuLoad reads the 1-minute load average from /proc/loadavg.
func cpuLoad() float64 {
	data, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return 0
	}
	var load float64
	fmt.Sscanf(string(data), "%f", &load)
	return load
}

// fillPool generates one poem for the most depleted (category, behavior) slot.
// Called once per quiet tick — never greedy.
func fillPool(db *sql.DB, llm *llmClient) {
	var bestCat, bestBeh string
	lowestCount := poolTarget

	for _, cat := range poolCategories {
		for _, beh := range poolBehaviors {
			var count int
			db.QueryRow(`SELECT COUNT(*) FROM poem_pool WHERE category = ? AND behavior = ?`,
				cat, beh).Scan(&count)
			if count < poolTarget && count < lowestCount {
				lowestCount = count
				bestCat = cat
				bestBeh = beh
			}
		}
	}

	if bestCat == "" {
		return // all slots at target
	}

	// Build a synthetic visit with a generic path for this category
	v := visit{
		Path:           syntheticPath(bestCat),
		Method:         "GET",
		AttackCategory: bestCat,
		Behavior:       bestBeh,
	}

	prompt, respType := promptForVisit(v, bestBeh)
	poem, err := llm.generate(prompt)
	if err != nil {
		log.Printf("pool fill error (%s/%s): %v", bestCat, bestBeh, err)
		return
	}
	if poem == "" {
		log.Printf("pool fill (%s/%s): empty after cleanup", bestCat, bestBeh)
		return
	}

	_, err = db.Exec(`INSERT INTO poem_pool (category, behavior, poem, response_type) VALUES (?, ?, ?, ?)`,
		bestCat, bestBeh, poem, respType)
	if err != nil {
		log.Printf("pool insert error: %v", err)
		return
	}

	log.Printf("pool: generated %s/%s poem (%d chars)", bestCat, bestBeh, len(poem))
}

// syntheticPath returns a representative path for a given attack category.
// Used for pool poem generation — generic enough to produce universal poems.
func syntheticPath(category string) string {
	paths := map[string]string{
		"wordpress":        "/wp-login.php",
		"webshell":         "/shell.php",
		"upload_exploit":   "/kcfinder/upload.php",
		"env_file":         "/.env",
		"vcs_leak":         "/.git/config",
		"admin_panel":      "/admin/",
		"path_traversal":   "/../../etc/passwd",
		"sqli_probe":       "/?id=1' OR 1=1--",
		"cms_fingerprint":  "/robots.txt",
		"api_probe":        "/api/v1",
		"iot_exploit":      "/cgi-bin/luci",
		"dev_tools":        "/phpinfo.php",
		"config_probe":     "/config.php.bak",
		"multi_protocol":   "/",
		"credential_submit": "/login",
		"generic_scan":     "/",
	}
	if p, ok := paths[category]; ok {
		return p
	}
	return "/"
}

// poolStats logs the current pool inventory.
func poolStats(db *sql.DB) {
	rows, err := db.Query(`
		SELECT category, behavior, COUNT(*) as cnt
		FROM poem_pool
		GROUP BY category, behavior
		ORDER BY category, behavior
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	var parts []string
	total := 0
	for rows.Next() {
		var cat, beh string
		var cnt int
		rows.Scan(&cat, &beh, &cnt)
		parts = append(parts, fmt.Sprintf("%s/%s:%d", cat, beh, cnt))
		total += cnt
	}
	if total > 0 {
		log.Printf("pool inventory: %d poems (%s)", total, strings.Join(parts, ", "))
	}
}
