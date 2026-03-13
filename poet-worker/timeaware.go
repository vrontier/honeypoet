package main

import (
	"fmt"
	"math"
	"math/rand"
	"time"
)

// timeChance is the probability of adding time-of-day flavor.
// 1-in-4 poems get a time hint (checked after code-poem and zeitgeist rolls).
const timeChance = 4

func shouldDoTimeAware() bool {
	return rand.Intn(timeChance) == 0
}

// localTimeOfDay computes the approximate local time of day from longitude
// using solar time (15 degrees = 1 hour). Returns a descriptor string.
func localTimeOfDay(longitude float64) string {
	offsetHours := longitude / 15.0
	utcNow := time.Now().UTC()
	localHour := (utcNow.Hour() + int(math.Round(offsetHours))) % 24
	if localHour < 0 {
		localHour += 24
	}

	switch {
	case localHour < 4:
		return "deep night"
	case localHour < 6:
		return "dawn"
	case localHour < 11:
		return "morning"
	case localHour < 14:
		return "midday"
	case localHour < 17:
		return "afternoon"
	case localHour < 20:
		return "dusk"
	case localHour < 22:
		return "evening"
	default:
		return "night"
	}
}

var timeMoods = map[string]string{
	"deep night": "It is deep night where this visitor is — the hours when only machines and insomniacs are awake",
	"dawn":       "Dawn is breaking where this visitor is — the threshold between darkness and light",
	"morning":    "It is morning where this visitor is — the world waking, fresh, full of intent",
	"midday":     "It is midday where this visitor is — the sun at its highest, shadows shortest",
	"afternoon":  "It is afternoon where this visitor is — the slow hours, the weight of the day settling",
	"dusk":       "Dusk is falling where this visitor is — the light fading, boundaries softening",
	"evening":    "It is evening where this visitor is — the world turning inward",
	"night":      "It is night where this visitor is — the quiet hours, the screen-lit dark",
}

// timeHint returns a prompt fragment about the time of day, or "" if
// longitude is unavailable.
func timeHint(lon float64, hasLon bool) string {
	if !hasLon {
		return ""
	}
	tod := localTimeOfDay(lon)
	mood, ok := timeMoods[tod]
	if !ok {
		return ""
	}
	return fmt.Sprintf("\n%s — let this color the mood subtly.", mood)
}
