package main

import (
	"database/sql"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"

	"gopkg.in/yaml.v3"
	_ "modernc.org/sqlite"
)

type config struct {
	DB       string `yaml:"db"`
	LLMURL   string `yaml:"llm_url"`
	LLMKey   string `yaml:"llm_api_key"`
	LLMModel string `yaml:"llm_model"`
	Interval string `yaml:"interval"`
	Batch    int    `yaml:"batch"`
}

func loadConfig(path string) (config, error) {
	cfg := config{
		Interval: "30s",
		Batch:    5,
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return cfg, fmt.Errorf("read config: %w", err)
	}
	if err := yaml.Unmarshal(data, &cfg); err != nil {
		return cfg, fmt.Errorf("parse config: %w", err)
	}

	if cfg.DB == "" {
		return cfg, fmt.Errorf("config: db is required")
	}
	if cfg.LLMURL == "" {
		return cfg, fmt.Errorf("config: llm_url is required")
	}
	if cfg.LLMKey != "" && cfg.LLMModel == "" {
		return cfg, fmt.Errorf("config: llm_model is required when llm_api_key is set")
	}

	return cfg, nil
}

// visit represents a row from the visits table.
type visit struct {
	ID             int64
	Path           string
	Method         string
	City           string
	Country        string
	AttackCategory string
	UserAgent      string
	VisitorID      sql.NullInt64
	Behavior       string
	VisitCount     int
}

func main() {
	configPath := flag.String("config", "config.yaml", "Path to configuration file")
	cleanup := flag.Bool("cleanup", false, "Re-run cleanPoem on all stored poems and exit")
	dryRun := flag.Bool("dry-run", false, "With -cleanup: show changes without applying")
	flag.Parse()

	log.SetFlags(log.Ldate | log.Ltime | log.Lmsgprefix)
	log.SetPrefix("poet-worker: ")

	cfg, err := loadConfig(*configPath)
	if err != nil {
		log.Fatalf("%v", err)
	}

	interval, err := time.ParseDuration(cfg.Interval)
	if err != nil {
		log.Fatalf("config: invalid interval %q: %v", cfg.Interval, err)
	}

	db, err := sql.Open("sqlite", cfg.DB+"?_pragma=journal_mode(WAL)&_pragma=busy_timeout(5000)")
	if err != nil {
		log.Fatalf("open database: %v", err)
	}
	defer func() { _ = db.Close() }()

	if *cleanup {
		runCleanup(db, *dryRun)
		return
	}

	// Verify connectivity
	if err := db.Ping(); err != nil {
		log.Fatalf("ping database: %v", err)
	}
	log.Printf("connected to %s", cfg.DB)

	// Schema migrations
	migrateSchema(db)
	migratePool(db)

	llm := newLLMClient(cfg.LLMURL, cfg.LLMKey, cfg.LLMModel)
	if cfg.LLMKey != "" {
		log.Printf("LLM endpoint: %s (cloud, model: %s)", cfg.LLMURL, cfg.LLMModel)
	} else {
		log.Printf("LLM endpoint: %s (local)", cfg.LLMURL)
	}

	// Graceful shutdown
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)

	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	// Run immediately on start, then on ticker
	processVisits(db, llm, cfg.Batch)

	for {
		select {
		case <-ticker.C:
			processVisits(db, llm, cfg.Batch)
		case sig := <-stop:
			log.Printf("received %v, shutting down", sig)
			return
		}
	}
}

// migrateSchema adds new columns to existing tables if missing.
func migrateSchema(db *sql.DB) {
	// Check if behavior column exists on visitors
	var count int
	err := db.QueryRow(`SELECT COUNT(*) FROM pragma_table_info('visitors') WHERE name = 'behavior'`).Scan(&count)
	if err != nil {
		log.Printf("schema check: %v", err)
		return
	}
	if count == 0 {
		if _, err := db.Exec(`ALTER TABLE visitors ADD COLUMN behavior TEXT`); err != nil {
			log.Printf("schema migration (behavior): %v", err)
		} else {
			log.Printf("schema migration: added behavior column to visitors")
		}
	}
}

// computeBehavior classifies a visitor's scanning pattern based on their history.
// Priority: hectic > relentless > methodical > patient > curious > ghostly.
func computeBehavior(db *sql.DB, visitorID int64) (behavior string, visitCount int) {
	var cnt int
	var cats int
	var firstTS, lastTS string

	err := db.QueryRow(`
		SELECT COUNT(*) as cnt,
		       COUNT(DISTINCT attack_category) as cats,
		       MIN(timestamp) as first_ts,
		       MAX(timestamp) as last_ts
		FROM visits WHERE visitor_id = ?
	`, visitorID).Scan(&cnt, &cats, &firstTS, &lastTS)
	if err != nil {
		return "", 0
	}

	visitCount = cnt

	if cnt <= 1 {
		return "ghostly", cnt
	}

	// Parse time span
	first, err1 := time.Parse("2006-01-02T15:04:05Z", firstTS)
	last, err2 := time.Parse("2006-01-02T15:04:05Z", lastTS)
	if err1 != nil || err2 != nil {
		// Fallback: try without T separator
		first, err1 = time.Parse("2006-01-02 15:04:05", firstTS)
		last, err2 = time.Parse("2006-01-02 15:04:05", lastTS)
	}

	var spanMinutes float64
	if err1 == nil && err2 == nil {
		spanMinutes = last.Sub(first).Minutes()
	}

	// Hectic: >10 req/min, broad categories, short span
	if spanMinutes > 0 && float64(cnt)/spanMinutes > 10 && cats >= 2 {
		return "hectic", cnt
	}

	// Relentless: 50+ visits, focused on 1-2 categories
	if cnt >= 50 && cats <= 2 {
		return "relentless", cnt
	}

	// Methodical: 4+ attack categories, systematic
	if cats >= 4 {
		return "methodical", cnt
	}

	// Patient: span >24h, keeps returning
	if spanMinutes > 24*60 {
		return "patient", cnt
	}

	// Curious: 2-3 categories, moderate pace
	if cats >= 2 && cats <= 3 {
		return "curious", cnt
	}

	// Default for single-category, moderate visitors
	return "ghostly", cnt
}

// processVisits finds unprocessed visits, generates poems, and fills the pool when quiet.
func processVisits(db *sql.DB, llm *llmClient, batchSize int) {
	// Count pending for quiet detection
	var pending int
	db.QueryRow(`SELECT COUNT(*) FROM visits WHERE llm_generated IS NULL OR llm_generated = 0`).Scan(&pending)

	rows, err := db.Query(`
		SELECT id, path, method,
			COALESCE(city, ''), COALESCE(country, ''),
			attack_category, COALESCE(user_agent, ''), visitor_id
		FROM visits
		WHERE llm_generated IS NULL OR llm_generated = 0
		ORDER BY id DESC
		LIMIT ?
	`, batchSize)
	if err != nil {
		log.Printf("query visits: %v", err)
		return
	}
	defer func() { _ = rows.Close() }()

	var visits []visit
	for rows.Next() {
		var v visit
		if err := rows.Scan(&v.ID, &v.Path, &v.Method, &v.City, &v.Country, &v.AttackCategory, &v.UserAgent, &v.VisitorID); err != nil {
			log.Printf("scan row: %v", err)
			continue
		}
		visits = append(visits, v)
	}
	if err := rows.Err(); err != nil {
		log.Printf("rows iteration: %v", err)
	}

	if len(visits) == 0 {
		// Nothing to process — perfect time to fill the pool
		if isQuiet(db, 0, batchSize) {
			fillPool(db, llm)
		}
		return
	}

	log.Printf("found %d visits to process", len(visits))

	for _, v := range visits {
		// Compute behavioral profile for this visitor
		var behavior string
		if v.VisitorID.Valid {
			behavior, v.VisitCount = computeBehavior(db, v.VisitorID.Int64)
			v.Behavior = behavior

			// Store behavior on the visitor record
			if behavior != "" {
				if _, err := db.Exec(`UPDATE visitors SET behavior = ? WHERE id = ?`, behavior, v.VisitorID.Int64); err != nil {
					log.Printf("update behavior for visitor %d: %v", v.VisitorID.Int64, err)
				}
			}
		}

		// Try to reuse a recent poem from the same visitor (same category, within 1 hour)
		if v.VisitorID.Valid {
			rtype, poem, found := findRecentPoem(db, v.VisitorID.Int64, v.AttackCategory)
			if found {
				if _, err := db.Exec(`UPDATE visits SET response_type = ?, response_content = ?, llm_generated = 1 WHERE id = ?`,
					rtype, poem, v.ID); err != nil {
					log.Printf("reuse poem for visit %d: %v", v.ID, err)
					continue
				}
				log.Printf("visit %d (%s): poem reused from visitor %d", v.ID, v.AttackCategory, v.VisitorID.Int64)
				continue
			}
		}

		// Try pool for high-frequency visitors
		if shouldUsePool(v) {
			poem, rtype, found := drawFromPool(db, v.AttackCategory, behavior)
			if found {
				if _, err := db.Exec(`UPDATE visits SET response_type = ?, response_content = ?, llm_generated = 1 WHERE id = ?`,
					rtype, poem, v.ID); err != nil {
					log.Printf("pool draw for visit %d: %v", v.ID, err)
					continue
				}
				log.Printf("visit %d (%s/%s): poem drawn from pool", v.ID, v.AttackCategory, behavior)
				continue
			}
		}

		prompt, respType := promptForVisit(v, behavior)
		poem, err := llm.generate(prompt)
		if err != nil {
			log.Printf("LLM error for visit %d (%s %s): %v", v.ID, v.Method, v.Path, err)
			// Mark as processed with llm_generated = -1 to avoid retrying forever
			markFailed(db, v.ID)
			continue
		}

		// If cleanup stripped everything (emoji spam, pure JSON, etc.), mark as failed
		if poem == "" {
			log.Printf("visit %d (%s): poem empty after cleanup, marking failed", v.ID, v.AttackCategory)
			markFailed(db, v.ID)
			continue
		}

		if _, err := db.Exec(`UPDATE visits SET response_type = ?, response_content = ?, llm_generated = 1 WHERE id = ?`,
			respType, poem, v.ID); err != nil {
			log.Printf("save poem for visit %d: %v", v.ID, err)
			continue
		}

		log.Printf("visit %d (%s/%s, %s): poem generated (%d chars)", v.ID, v.AttackCategory, respType, behavior, len(poem))
	}

	// Fill pool during quiet periods (one poem per tick, never greedy)
	if isQuiet(db, pending, batchSize) {
		fillPool(db, llm)
	}
}

// findRecentPoem looks for an existing LLM poem from the same visitor and attack
// category within 1 hour.
func findRecentPoem(db *sql.DB, visitorID int64, category string) (responseType, poem string, found bool) {
	err := db.QueryRow(`
		SELECT response_type, response_content
		FROM visits
		WHERE visitor_id = ?
		  AND attack_category = ?
		  AND llm_generated = 1
		  AND timestamp >= datetime('now', '-1 hour')
		ORDER BY id DESC
		LIMIT 1
	`, visitorID, category).Scan(&responseType, &poem)
	if err != nil {
		return "", "", false
	}
	return responseType, poem, true
}

// savePoem updates a visit row with the LLM-generated poem.
func savePoem(db *sql.DB, visitID int64, category string, poem string) error {
	responseType := responseTypeFor(category)
	_, err := db.Exec(`
		UPDATE visits
		SET response_type = ?, response_content = ?, llm_generated = 1
		WHERE id = ?
	`, responseType, poem, visitID)
	return err
}

// markFailed marks a visit as failed so it won't be retried.
func markFailed(db *sql.DB, visitID int64) {
	_, err := db.Exec(`UPDATE visits SET llm_generated = -1 WHERE id = ?`, visitID)
	if err != nil {
		log.Printf("mark failed for visit %d: %v", visitID, err)
	}
}

// responseTypeFor maps attack category to a response_type label.
func responseTypeFor(category string) string {
	switch category {
	case "wordpress":
		return "llm_haiku"
	case "webshell":
		return "llm_shell"
	case "upload_exploit":
		return "llm_upload"
	case "env_file":
		return "llm_env"
	case "vcs_leak":
		return "llm_vcs"
	case "admin_panel":
		return "llm_login"
	case "path_traversal":
		return "llm_story"
	case "sqli_probe":
		return "llm_sql"
	case "cms_fingerprint":
		return "llm_haiku"
	case "api_probe":
		return "llm_json"
	case "iot_exploit":
		return "llm_device"
	case "dev_tools":
		return "llm_trace"
	case "config_probe":
		return "llm_config"
	case "multi_protocol":
		return "llm_protocol"
	case "credential_submit":
		return "llm_mirror"
	default:
		return "llm_verse"
	}
}

// runCleanup re-runs cleanPoem on all stored poems, reconstructing the original
// prompt for prompt-aware echo detection. Poems that become empty are marked as
// failed. This is for one-time use after improving the cleanup logic.
func runCleanup(db *sql.DB, dryRun bool) {
	rows, err := db.Query(`
		SELECT v.id, v.response_content, v.path, v.method,
			COALESCE(v.city, ''), COALESCE(v.country, ''),
			v.attack_category, COALESCE(v.user_agent, ''),
			v.visitor_id, COALESCE(vr.behavior, '')
		FROM visits v
		LEFT JOIN visitors vr ON v.visitor_id = vr.id
		WHERE v.llm_generated = 1 AND v.response_content IS NOT NULL
		ORDER BY v.id
	`)
	if err != nil {
		log.Fatalf("query: %v", err)
	}
	defer func() { _ = rows.Close() }()

	var updated, emptied int
	for rows.Next() {
		var id int64
		var content string
		var v visit
		if err := rows.Scan(&id, &content, &v.Path, &v.Method, &v.City, &v.Country,
			&v.AttackCategory, &v.UserAgent, &v.VisitorID, &v.Behavior); err != nil {
			log.Printf("scan row: %v", err)
			continue
		}

		// Reconstruct prompt for echo detection
		prompt, _ := promptForVisit(v, v.Behavior)
		cleaned := cleanPoem(content, prompt)
		if cleaned == content {
			continue
		}

		if cleaned == "" {
			if dryRun {
				log.Printf("EMPTY  visit %d: %.80s", id, content)
			} else {
				if _, err := db.Exec(`UPDATE visits SET llm_generated = -1 WHERE id = ?`, id); err != nil {
					log.Printf("mark failed visit %d: %v", id, err)
				}
			}
			emptied++
		} else {
			if dryRun {
				log.Printf("UPDATE visit %d:\n  BEFORE: %.100s\n  AFTER:  %.100s", id, content, cleaned)
			} else {
				if _, err := db.Exec(`UPDATE visits SET response_content = ? WHERE id = ?`, cleaned, id); err != nil {
					log.Printf("update visit %d: %v", id, err)
				}
			}
			updated++
		}
	}

	mode := "Applied"
	if dryRun {
		mode = "Would apply"
	}
	log.Printf("%s: %d poems updated, %d poems emptied (marked failed)", mode, updated, emptied)
}
