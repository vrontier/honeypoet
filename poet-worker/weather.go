package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"math/rand"
	"net/http"
	"sync"
	"time"
)

// weatherChance is the probability of adding weather flavor.
// 1-in-4 poems get a weather hint.
const weatherChance = 4

// weatherCacheTTL is how long a cached weather entry stays fresh.
const weatherCacheTTL = 2 * time.Hour

type weatherData struct {
	description string
	fetchedAt   time.Time
}

type weatherStore struct {
	mu   sync.RWMutex
	data map[string]weatherData // key: "lat,lon" rounded to 1 decimal
}

var weather = &weatherStore{
	data: make(map[string]weatherData),
}

var weatherClient = &http.Client{Timeout: 10 * time.Second}

func shouldDoWeather() bool {
	return rand.Intn(weatherChance) == 0
}

// gridKey rounds coordinates to 1 decimal place for cache grouping.
// ~11 km resolution — close enough for weather, clusters nearby data centers.
func gridKey(lat, lon float64) string {
	return fmt.Sprintf("%.1f,%.1f", lat, lon)
}

// wmoDescription maps WMO weather codes to human-readable descriptions.
func wmoDescription(code int) string {
	switch code {
	case 0:
		return "clear sky"
	case 1:
		return "mainly clear"
	case 2:
		return "partly cloudy"
	case 3:
		return "overcast"
	case 45, 48:
		return "fog"
	case 51:
		return "light drizzle"
	case 53:
		return "drizzle"
	case 55:
		return "dense drizzle"
	case 56, 57:
		return "freezing drizzle"
	case 61:
		return "light rain"
	case 63:
		return "rain"
	case 65:
		return "heavy rain"
	case 66, 67:
		return "freezing rain"
	case 71:
		return "light snow"
	case 73:
		return "snow"
	case 75:
		return "heavy snow"
	case 77:
		return "snow grains"
	case 80:
		return "light rain showers"
	case 81:
		return "rain showers"
	case 82:
		return "violent rain showers"
	case 85:
		return "light snow showers"
	case 86:
		return "heavy snow showers"
	case 95:
		return "thunderstorm"
	case 96, 99:
		return "thunderstorm with hail"
	default:
		return ""
	}
}

// openMeteoResponse is the subset of the Open-Meteo JSON response we need.
type openMeteoResponse struct {
	Current struct {
		Temperature float64 `json:"temperature_2m"`
		WeatherCode int     `json:"weather_code"`
	} `json:"current"`
}

// fetchWeather calls the Open-Meteo API for current weather at the given coordinates.
func fetchWeather(lat, lon float64) (string, error) {
	url := fmt.Sprintf(
		"https://api.open-meteo.com/v1/forecast?latitude=%.2f&longitude=%.2f&current=temperature_2m,weather_code",
		lat, lon,
	)
	resp, err := weatherClient.Get(url)
	if err != nil {
		return "", fmt.Errorf("weather GET: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("weather GET: status %d", resp.StatusCode)
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("weather read: %w", err)
	}

	var result openMeteoResponse
	if err := json.Unmarshal(body, &result); err != nil {
		return "", fmt.Errorf("weather parse: %w", err)
	}

	desc := wmoDescription(result.Current.WeatherCode)
	if desc == "" {
		desc = "unknown conditions"
	}

	return fmt.Sprintf("%s, %.0f°C", desc, result.Current.Temperature), nil
}

// getWeather returns the current weather description for the given coordinates,
// using the cache when possible. Returns "" on any failure.
func getWeather(lat, lon float64) string {
	key := gridKey(lat, lon)

	// Check cache
	weather.mu.RLock()
	if cached, ok := weather.data[key]; ok && time.Since(cached.fetchedAt) < weatherCacheTTL {
		weather.mu.RUnlock()
		return cached.description
	}
	weather.mu.RUnlock()

	// Fetch fresh
	desc, err := fetchWeather(lat, lon)
	if err != nil {
		log.Printf("weather: %v", err)
		return ""
	}

	// Store in cache
	weather.mu.Lock()
	weather.data[key] = weatherData{description: desc, fetchedAt: time.Now()}
	weather.mu.Unlock()

	return desc
}

// weatherHint returns a prompt fragment about the weather, or "" if
// coordinates are unavailable or the fetch fails.
func weatherHint(lat, lon float64, hasCoords bool) string {
	if !hasCoords {
		return ""
	}
	desc := getWeather(lat, lon)
	if desc == "" {
		return ""
	}
	return fmt.Sprintf("\nThe weather where this visitor is: %s — let this atmosphere seep into the poem, as mood or metaphor, not as a weather report.", desc)
}
