package main

import (
	"database/sql"
	"encoding/xml"
	"fmt"
	"io"
	"log"
	"math/rand"
	"net/http"
	"sync"
	"time"
)

// trendCountries are the geo codes to fetch trends for,
// based on our top visitor countries.
var trendCountries = []string{
	"US", "DE", "SE", "RU", "SG", "HK", "CA", "TW",
}

// zeitgeistChance is the probability of weaving a trending topic into a poem.
// 1-in-5 poems get a zeitgeist flavor (checked after the 1-in-33 code-poem roll).
const zeitgeistChance = 5

// trendRefreshInterval is how often we fetch fresh trends.
const trendRefreshInterval = 1 * time.Hour

// trendCache holds the in-memory cache of trending topics per country.
type trendCache struct {
	mu        sync.RWMutex
	topics    map[string][]string // country code -> list of topic titles
	lastFetch time.Time
}

var trends = &trendCache{
	topics: make(map[string][]string),
}

// rssFeed represents the Google Trends RSS feed structure.
type rssFeed struct {
	XMLName xml.Name   `xml:"rss"`
	Channel rssChannel `xml:"channel"`
}

type rssChannel struct {
	Items []rssItem `xml:"item"`
}

type rssItem struct {
	Title   string `xml:"title"`
	Traffic string `xml:"approx_traffic"`
}

// migrateTrends creates the daily_trends table if it doesn't exist.
func migrateTrends(db *sql.DB) {
	_, err := db.Exec(`
		CREATE TABLE IF NOT EXISTS daily_trends (
			id         INTEGER PRIMARY KEY AUTOINCREMENT,
			country    TEXT NOT NULL,
			topic      TEXT NOT NULL,
			fetched_at TEXT NOT NULL DEFAULT (datetime('now'))
		)
	`)
	if err != nil {
		log.Printf("trends migration: %v", err)
		return
	}
	db.Exec(`CREATE INDEX IF NOT EXISTS idx_trends_country ON daily_trends (country)`)
	db.Exec(`CREATE INDEX IF NOT EXISTS idx_trends_fetched ON daily_trends (fetched_at)`)
}

// refreshTrends fetches trending topics from Google Trends RSS for each country
// and updates both the DB and in-memory cache. Call this hourly.
func refreshTrends(db *sql.DB) {
	trends.mu.Lock()
	defer trends.mu.Unlock()

	// Skip if we fetched recently
	if time.Since(trends.lastFetch) < trendRefreshInterval {
		return
	}

	client := &http.Client{Timeout: 15 * time.Second}
	fetched := 0

	for _, geo := range trendCountries {
		topics, err := fetchTrendsRSS(client, geo)
		if err != nil {
			log.Printf("trends fetch %s: %v", geo, err)
			continue
		}
		if len(topics) == 0 {
			continue
		}

		trends.topics[geo] = topics
		fetched += len(topics)

		// Store in DB — delete old entries for this country, insert fresh
		db.Exec(`DELETE FROM daily_trends WHERE country = ?`, geo)
		for _, topic := range topics {
			db.Exec(`INSERT INTO daily_trends (country, topic) VALUES (?, ?)`, geo, topic)
		}
	}

	trends.lastFetch = time.Now()
	if fetched > 0 {
		log.Printf("trends: refreshed %d topics across %d countries", fetched, len(trendCountries))
	}
}

// fetchTrendsRSS fetches and parses the Google Trends RSS feed for a country.
func fetchTrendsRSS(client *http.Client, geo string) ([]string, error) {
	url := fmt.Sprintf("https://trends.google.com/trending/rss?geo=%s", geo)
	resp, err := client.Get(url)
	if err != nil {
		return nil, fmt.Errorf("GET %s: %w", geo, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("GET %s: status %d", geo, resp.StatusCode)
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("read %s: %w", geo, err)
	}

	var feed rssFeed
	if err := xml.Unmarshal(body, &feed); err != nil {
		return nil, fmt.Errorf("parse %s: %w", geo, err)
	}

	var topics []string
	for _, item := range feed.Channel.Items {
		if item.Title != "" {
			topics = append(topics, item.Title)
		}
	}
	return topics, nil
}

// loadTrendsFromDB populates the in-memory cache from the DB on startup,
// so we don't need to wait for the first hourly fetch.
func loadTrendsFromDB(db *sql.DB) {
	trends.mu.Lock()
	defer trends.mu.Unlock()

	rows, err := db.Query(`
		SELECT country, topic FROM daily_trends
		WHERE fetched_at >= datetime('now', '-2 hours')
		ORDER BY country, id
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	loaded := 0
	for rows.Next() {
		var country, topic string
		if err := rows.Scan(&country, &topic); err != nil {
			continue
		}
		trends.topics[country] = append(trends.topics[country], topic)
		loaded++
	}

	if loaded > 0 {
		log.Printf("trends: loaded %d cached topics from DB", loaded)
	}
}

// pickTrend returns a random trending topic for the given country code.
// Falls back to US trends if the country has no data. Returns "" if no trends available.
func pickTrend(country string) string {
	trends.mu.RLock()
	defer trends.mu.RUnlock()

	topics := trends.topics[country]
	if len(topics) == 0 {
		// Fallback to US trends
		topics = trends.topics["US"]
	}
	if len(topics) == 0 {
		return ""
	}
	return topics[rand.Intn(len(topics))]
}

// shouldDoZeitgeist returns true if this poem should get a zeitgeist flavor.
func shouldDoZeitgeist() bool {
	return rand.Intn(zeitgeistChance) == 0
}

// zeitgeistHint returns a prompt fragment weaving in a trending topic,
// or "" if no topic is available for the country.
func zeitgeistHint(country string) string {
	topic := pickTrend(country)
	if topic == "" {
		return ""
	}
	return fmt.Sprintf("\nToday the world is searching for \"%s\" — let this echo subtly in the poem, as atmosphere or metaphor, not as the main subject.", topic)
}
