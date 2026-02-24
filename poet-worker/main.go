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

// processVisits finds unprocessed visits and generates poems for them.
func processVisits(db *sql.DB, llm *llmClient, batchSize int) {
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
		return
	}

	log.Printf("found %d visits to process", len(visits))

	for _, v := range visits {
		// Try to reuse a recent poem from the same visitor (within 4 hours)
		if v.VisitorID.Valid {
			rtype, poem, found := findRecentPoem(db, v.VisitorID.Int64)
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

		prompt, respType := promptForVisit(v)
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

		log.Printf("visit %d (%s/%s): poem generated (%d chars)", v.ID, v.AttackCategory, respType, len(poem))
	}
}

// findRecentPoem looks for an existing LLM poem from the same visitor within 4 hours.
func findRecentPoem(db *sql.DB, visitorID int64) (responseType, poem string, found bool) {
	err := db.QueryRow(`
		SELECT response_type, response_content
		FROM visits
		WHERE visitor_id = ?
		  AND llm_generated = 1
		  AND timestamp >= datetime('now', '-4 hours')
		ORDER BY id DESC
		LIMIT 1
	`, visitorID).Scan(&responseType, &poem)
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
	case "env_file":
		return "llm_env"
	case "admin_panel":
		return "llm_login"
	case "path_traversal":
		return "llm_story"
	case "sqli_probe":
		return "llm_sql"
	case "api_probe":
		return "llm_json"
	case "dev_tools":
		return "llm_trace"
	case "config_probe":
		return "llm_config"
	case "credential_submit":
		return "llm_mirror"
	default:
		return "llm_verse"
	}
}

// runCleanup re-runs cleanPoem on all stored poems. Poems that become empty
// are marked as failed. This is for one-time use after improving the cleanup logic.
func runCleanup(db *sql.DB, dryRun bool) {
	rows, err := db.Query(`
		SELECT id, response_content FROM visits
		WHERE llm_generated = 1 AND response_content IS NOT NULL
		ORDER BY id
	`)
	if err != nil {
		log.Fatalf("query: %v", err)
	}
	defer func() { _ = rows.Close() }()

	var updated, emptied int
	for rows.Next() {
		var id int64
		var content string
		if err := rows.Scan(&id, &content); err != nil {
			log.Printf("scan row: %v", err)
			continue
		}

		cleaned := cleanPoem(content)
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
