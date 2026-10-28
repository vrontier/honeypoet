package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

// TestGenerateEndpoint checks that generate always uses the chat endpoint and
// only sends an Authorization header when an API key is configured.
func TestGenerateEndpoint(t *testing.T) {
	tests := []struct {
		name     string
		apiKey   string
		model    string
		wantAuth string
	}{
		{name: "local llama-server, no key", apiKey: "", model: "", wantAuth: ""},
		{name: "cloud provider with key", apiKey: "sk-test", model: "gpt-4o-mini", wantAuth: "Bearer sk-test"},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			var gotPath, gotAuth string
			var gotReq chatRequest
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				gotPath = r.URL.Path
				gotAuth = r.Header.Get("Authorization")
				_, hasAuth := r.Header["Authorization"]
				if tt.apiKey == "" && hasAuth {
					t.Errorf("Authorization header sent without an API key: %q", gotAuth)
				}
				if err := json.NewDecoder(r.Body).Decode(&gotReq); err != nil {
					t.Errorf("decode request: %v", err)
				}
				_, _ = w.Write([]byte(`{"choices":[{"message":{"role":"assistant","content":"A quiet knock upon the door,\nThe server hums forevermore."}}]}`))
			}))
			defer srv.Close()

			c := newLLMClient(srv.URL+"/", tt.apiKey, tt.model)
			got, err := c.generate("Write a poem.", "llm_verse")
			if err != nil {
				t.Fatalf("generate() error: %v", err)
			}
			if gotPath != "/v1/chat/completions" {
				t.Errorf("path = %q, want /v1/chat/completions", gotPath)
			}
			if gotAuth != tt.wantAuth {
				t.Errorf("Authorization = %q, want %q", gotAuth, tt.wantAuth)
			}
			if gotReq.Model != tt.model {
				t.Errorf("model = %q, want %q", gotReq.Model, tt.model)
			}
			if len(gotReq.Messages) != 1 || gotReq.Messages[0].Role != "user" || gotReq.Messages[0].Content != "Write a poem." {
				t.Errorf("messages = %+v, want single user message", gotReq.Messages)
			}
			if gotReq.MaxTokens != 150 || len(gotReq.Stop) != 2 || gotReq.Stop[0] != "\n\n" {
				t.Errorf("verse settings: max_tokens=%d stop=%q", gotReq.MaxTokens, gotReq.Stop)
			}
			if want := "A quiet knock upon the door,\nThe server hums forevermore."; got != want {
				t.Errorf("generate() = %q, want %q", got, want)
			}
		})
	}
}

// TestGenerateCode checks code-poems are generated without the blank-line
// stop (code has blank lines) and cleaned with cleanCode, not cleanPoem.
func TestGenerateCode(t *testing.T) {
	var gotReq chatRequest
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := json.NewDecoder(r.Body).Decode(&gotReq); err != nil {
			t.Errorf("decode request: %v", err)
		}
		_, _ = w.Write([]byte(`{"choices":[{"message":{"role":"assistant","content":"` + "```" + `perl\nuse strict;\n\nmy $seeker = \"door\";\n` + "```" + `"}}]}`))
	}))
	defer srv.Close()

	got, err := newLLMClient(srv.URL, "", "").generate("Write code.", "llm_code")
	if err != nil {
		t.Fatalf("generate() error: %v", err)
	}
	for _, stop := range gotReq.Stop {
		if stop == "\n\n" {
			t.Errorf("code request has blank-line stop: %q", gotReq.Stop)
		}
	}
	if gotReq.MaxTokens != codeMaxTokens {
		t.Errorf("max_tokens = %d, want %d", gotReq.MaxTokens, codeMaxTokens)
	}
	if want := "use strict;\n\nmy $seeker = \"door\";"; got != want {
		t.Errorf("generate() = %q, want %q", got, want)
	}
}

func TestCleanCode(t *testing.T) {
	fence := "```"
	tests := []struct {
		name string
		raw  string
		want string
	}{
		{
			name: "fenced block keeps blank lines and braces",
			raw:  fence + "c\n#include <stdio.h>\n\nint main() {\n  printf(\"knock\\n\");\n  return 0;\n}\n" + fence,
			want: "#include <stdio.h>\n\nint main() {\n  printf(\"knock\\n\");\n  return 0;\n}",
		},
		{
			name: "prose around the fence is dropped",
			raw:  "Here's a little program:\n" + fence + "python\nseeker = \"door\"\nprint(seeker)\n" + fence + "\nThis program greets the seeker.",
			want: "seeker = \"door\"\nprint(seeker)",
		},
		{
			name: "unfenced code kept as is",
			raw:  "10 PRINT \"A seeker knocks\"\n20 END",
			want: "10 PRINT \"A seeker knocks\"\n20 END",
		},
		{
			name: "unclosed fence (cut off) takes the rest",
			raw:  fence + "go\nfunc main() {\n\tseeker := \"door\"",
			want: "func main() {\n\tseeker := \"door\"",
		},
		{
			name: "IP addresses stripped",
			raw:  "echo \"knock from 10.0.0.1 at the door\"",
			want: "echo \"knock from  at the door\"",
		},
		{
			name: "empty fence",
			raw:  fence + "rust\n" + fence,
			want: "",
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := cleanCode(tt.raw); got != tt.want {
				t.Errorf("cleanCode() =\n%q\nwant:\n%q", got, tt.want)
			}
		})
	}
}

// TestGenerateErrors checks HTTP errors and empty content are reported.
func TestGenerateErrors(t *testing.T) {
	tests := []struct {
		name   string
		status int
		body   string
	}{
		{name: "server error", status: http.StatusInternalServerError, body: `{"error":"boom"}`},
		{name: "no choices", status: http.StatusOK, body: `{"choices":[]}`},
		{name: "blank content", status: http.StatusOK, body: `{"choices":[{"message":{"role":"assistant","content":"  \n"}}]}`},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(tt.status)
				_, _ = w.Write([]byte(tt.body))
			}))
			defer srv.Close()
			if _, err := newLLMClient(srv.URL, "", "").generate("Write a poem.", "llm_verse"); err == nil {
				t.Error("generate() error = nil, want error")
			}
		})
	}
}

func TestCleanPoem(t *testing.T) {
	tests := []struct {
		name   string
		raw    string
		prompt string
		want   string
	}{
		{
			name: "clean poem unchanged",
			raw:  "A gentle knock echoes through the void,\nFrom distant shores, a silent voice.",
			want: "A gentle knock echoes through the void,\nFrom distant shores, a silent voice.",
		},
		{
			name: "remember instruction echo + emoji spam",
			raw:  "Remember, you are the Honeypoet, and your voice should be thoughtful yet gentle. \n\n🌹🖋️ 🌹🖋️ 🌹🖋️",
			want: "",
		},
		{
			name: "remember colon instruction echo",
			raw:  "Remember: You are the Honeypoet — a server that turns internet scanner traffic into verse.\nYour tone is curious, warm, and philosophical.\n\n🌐 In Frankfurt's heart, a digital footstep echoes,\nSeekers of data, in vast cyber expanses.",
			want: "🌐 In Frankfurt's heart, a digital footstep echoes,\nSeekers of data, in vast cyber expanses.",
		},
		{
			name: "JSON preamble with meta-commentary",
			raw:  "The visit\nends with a question mark.\n\njson\n{\n    \"path\": \"/\",\n    \"method\": \"GET\"\n}\n\nA gentle knock echoes through the void,\nFrom Assendelft's heart, a silent voice.",
			want: "A gentle knock echoes through the void,\nFrom Assendelft's heart, a silent voice.",
		},
		{
			name: "show compassion + rules block",
			raw:  "Show compassion for the visitor, but remember you are the machine.\n\n---\n\n**Honeypoet's Rules:**\n\n- **Do not mention** any URLs\n- **Avoid** any words\n\n---\n\nIn the vast, silent city of ones and zeros,\nA distant whisper, a gentle knock.",
			want: "In the vast, silent city of ones and zeros,\nA distant whisper, a gentle knock.",
		},
		{
			name: "pure JSON block",
			raw:  "json\n{\n  \"path\": \"/manager.php\",\n  \"method\": \"GET\",\n  \"from\": \"Tokyo, JP\"\n}",
			want: "",
		},
		{
			name: "emoji only",
			raw:  "🌹🕊️🌐",
			want: "",
		},
		{
			name: "answer with + JSON poem",
			raw:  "Answer with the poem only.\n\njson\n{\n  \"poem\": \"In Tokyo's embrace, a gentle knock\"\n}",
			want: "",
		},
		{
			name: "be gentle + emoji trailing",
			raw:  "Be gentle in your reflection.\n\n🌹🕰️🌐",
			want: "",
		},
		{
			name: "and remember + emoji then poem",
			raw:  "And remember: you are the Honeypoet, and you write with curiosity and wonder.\n\n🖋️\n\nIn Tokyo's embrace, a GET request whispers,\nA digital soul seeks solace here.",
			want: "In Tokyo's embrace, a GET request whispers,\nA digital soul seeks solace here.",
		},
		{
			name: "IP addresses stripped",
			raw:  "From 192.168.1.1 a knock arrives,\nSeeking what the server hides.",
			want: "From  a knock arrives,\nSeeking what the server hides.",
		},
		{
			name: "inline emoji preserved in real poem",
			raw:  "🌐 In Frankfurt's heart, a digital footstep echoes,\n📤 A HEAD request, a silent knock upon our door.",
			want: "🌐 In Frankfurt's heart, a digital footstep echoes,\n📤 A HEAD request, a silent knock upon our door.",
		},
		{
			name: "close with instruction",
			raw:  "Close with a gentle invitation to return.\n\nA fleeting whisper, a digital knock,\nFrom Tokyo's heart, a silent clock.",
			want: "A fleeting whisper, a digital knock,\nFrom Tokyo's heart, a silent clock.",
		},
		{
			name: "self-instruction prefix stripped",
			raw:  "Each line should end with a shrine name, and the shrine name must be a palindrome.\n\nA traveler scans:\nStockholm's streets, WP found,\nIn a palindrome shrine.",
			want: "A traveler scans:\nStockholm's streets, WP found,\nIn a palindrome shrine.",
		},
		{
			name: "answer after separator",
			raw:  "Reflect on the scanner's visits as growth rings of curiosity.\n\n---\n\nAnswer:\n\nRing of inquiry,\nStockholm's gentle probe,\nCuriosity's tree.",
			want: "Ring of inquiry,\nStockholm's gentle probe,\nCuriosity's tree.",
		},
		{
			name: "trailing note after separator",
			raw:  "In the digital tide,\nHoneypoet listens,\nChange flows, truth stays.\n\n---\n\nNote: This poem does not include the requested haiku, but it captures the essence.",
			want: "In the digital tide,\nHoneypoet listens,\nChange flows, truth stays.",
		},
		{
			name: "no more no less prefix",
			raw:  "No more, no less. And no less than one word in each line.\n\nIn the sea's embrace,\nStockholm's visitor,\nTides return, repeat.",
			want: "In the sea's embrace,\nStockholm's visitor,\nTides return, repeat.",
		},
		{
			name: "prompt template echo stripped",
			raw:  "- Title: <title-of-poem>\n- Lines: <lines-of-poem>\n\nTitle: The Single Thread\nLines:\nIn solitude, a seeker weaves its art.",
			want: "In solitude, a seeker weaves its art.",
		},
		{
			name: "pure self-constraints no poem",
			raw:  "- Reflect on the human desire to return, revisit, and renew.\n- Use a metaphor of the sea to explore this impulse.\n- Contrast the constancy of tides with the unique nature of each visit.",
			want: "",
		},
		{
			name: "spill prefix then poem",
			raw:  "No other introductions.\n\nWisdom's thin line grows,\nStockholm's hand, a gentle touch,\nGrowth in wood, unseen.",
			want: "Wisdom's thin line grows,\nStockholm's hand, a gentle touch,\nGrowth in wood, unseen.",
		},
		{
			name: "haiku label before poem",
			raw:  "Reflect on the pilgrimage of the internet and its travelers.\n\nHaiku:\n\nScans probe in Sweden,\nWordpress seeks truth anew.\nPilgrimage of data.",
			want: "Scans probe in Sweden,\nWordpress seeks truth anew.\nPilgrimage of data.",
		},
		{
			name: "word constraints then poem",
			raw:  "- The word \"tree\"\n- The word \"visitor\"\n\n- Tree's thin rings\n- Visitor's steady probe\n- Stockholm's 34th knock.",
			want: "- Tree's thin rings\n- Visitor's steady probe\n- Stockholm's 34th knock.",
		},
		{
			name: "standalone honeypoet too short",
			raw:  "Honeypoet",
			want: "",
		},
		{
			name: "syllable constraints no poem",
			raw:  "- Lines of 5-6 syllables\n- Include the words \"torrid,\" \"window,\" and \"knocks\"\n- No punctuation\n- No capitalization",
			want: "",
		},
		// Contamination cleanup tests
		{
			name: "Honeypoet: prefix stripped",
			raw:  "Honeypoet:\nA knock echoes through the wire,\nSeeking what was never there.",
			want: "A knock echoes through the wire,\nSeeking what was never there.",
		},
		{
			name: "Your poem: prefix stripped",
			raw:  "Your poem:\nThe fog rolls in from distant shores,\nA scanner's breath upon the door.",
			want: "The fog rolls in from distant shores,\nA scanner's breath upon the door.",
		},
		{
			name: "Mention instruction echo is noise",
			raw:  "- Mention the city and country of origin\n- Incorporate the idea of digital wandering\n- Start with: a question about belonging",
			want: "",
		},
		{
			name: "Start with instruction as prefix",
			raw:  "Start with a metaphor about tides.\n\nThe tide pulls data from the shore,\nEach wave a packet, nothing more.",
			want: "The tide pulls data from the shore,\nEach wave a packet, nothing more.",
		},
		{
			name: "blockquote markers stripped",
			raw:  "> A knock at the gate,\n> No one answers but the wind,\n> Packets drift like leaves.",
			want: "A knock at the gate,\nNo one answers but the wind,\nPackets drift like leaves.",
		},
		{
			name: "blockquote partial lines",
			raw:  "> The scanner arrives\nsilent as morning frost\n> and leaves no trace.",
			want: "The scanner arrives\nsilent as morning frost\nand leaves no trace.",
		},
		{
			name: "fence label fortran stripped",
			raw:  "fortran\nPROGRAM seeker\n  PRINT *, 'knock knock'\nEND PROGRAM seeker",
			want: "PROGRAM seeker\n  PRINT *, 'knock knock'\nEND PROGRAM seeker",
		},
		{
			name: "server: header echo stripped",
			raw:  "server: nginx/1.24\n\nA quiet knock upon the door,\nThe server hums forevermore.",
			want: "A quiet knock upon the door,\nThe server hums forevermore.",
		},
		// Bare URL path detection
		{
			name: "bare path as list item",
			raw:  "- /wp-login.php",
			want: "",
		},
		{
			name: "bare path on its own",
			raw:  "/wp-admin/setup-config.php",
			want: "",
		},
		{
			name: "list of paths stripped to nothing",
			raw:  "- /wp-login.php\n- /wp-admin/\n- /xmlrpc.php",
			want: "",
		},
		// Prompt-aware echo detection tests
		{
			name:   "prompt echo: format line repeated in response",
			raw:    "Waves lap at the shore.\n\nHaiku (5-7-5 syllables, three lines), evoking tides:\nAnother wave comes.",
			prompt: "Write only the poem.\n\nA scanner just probed:\n\nHaiku (5-7-5 syllables, three lines), evoking tides:\n",
			want:   "Waves lap at the shore.",
		},
		{
			name:   "prompt echo: preamble echoed before poem",
			raw:    "Your tone is curious, warm, and philosophical. Never hostile or mocking.\n\nStockholm calls again,\nWordPress sleeps in silence here,\nDoor of fog remains.",
			prompt: "You are the Honeypoet.\nYour tone is curious, warm, and philosophical. Never hostile or mocking.\n\nA scanner just probed:\n\nHaiku:\n",
			want:   "Stockholm calls again,\nWordPress sleeps in silence here,\nDoor of fog remains.",
		},
		{
			name:   "prompt echo: context line echoed",
			raw:    "- Path: /wp-admin/setup-config.php\n- From: Stockholm, SE\n\nThe pilgrim returns,\nKnocking on the fog again,\nNo WordPress was here.",
			prompt: "Preamble here.\n\nA scanner just probed:\n- Path: /wp-admin/setup-config.php\n- From: Stockholm, SE\n\nHaiku:\n",
			want:   "The pilgrim returns,\nKnocking on the fog again,\nNo WordPress was here.",
		},
		// Chat-endpoint output reuses prompt context as poem material (Oct 2026).
		// Fragments of a prompt line must not truncate the poem.
		{
			name:   "prompt echo: city name line is not an echo",
			raw:    "A shadow at the gate, then gone,\nFrankfurt am Main,\nwe leave the light on.",
			prompt: "Preamble here.\n\nA ghostly visitor just knocked on this door:\n- Path: /wp-login.php\n- From: Frankfurt am Main, DE\n\nShort poem (4-8 lines):\n",
			want:   "A shadow at the gate, then gone,\nFrankfurt am Main,\nwe leave the light on.",
		},
		{
			name:   "prompt echo: theme image fragment is not an echo",
			raw:    "A knock, and then the hush again,\na single footprint on a beach,\nfilling slowly with the sea.",
			prompt: "Preamble here.\n\nA ghostly visitor just knocked on this door:\n- Path: /\n\nShort poem (4-8 lines) about this visit, evoking a single footprint on a beach, already filling with water:\n",
			want:   "A knock, and then the hush again,\na single footprint on a beach,\nfilling slowly with the sea.",
		},
		{
			name:   "prompt echo: near-complete prompt line still truncates",
			raw:    "Waves lap at the shore.\nShort poem (4-8 lines) about this visit\nAnother wave comes.",
			prompt: "Preamble here.\n\nShort poem (4-8 lines) about this visit:\n",
			want:   "Waves lap at the shore.",
		},
		// --- Phi-specific failure patterns (observed Mar 2026) ---
		{
			name: "mid-line instruction bleed: let this color the mood",
			raw:  "The hues of dusk, the fading light — let this color the mood subtly.\nShadows stretch, the sun dips — let this be felt.\nWhere this door has hung so long — it witnesses many such stories.",
			want: "The hues of dusk, the fading light\nShadows stretch, the sun dips\nWhere this door has hung so long — it witnesses many such stories.",
		},
		{
			name: "mid-line instruction bleed: em-dash let this",
			raw:  "Sunlit water. A trace left behind.\nOn this beach, the water is already filling — let this be the mood or emotion of the poem.",
			want: "Sunlit water. A trace left behind.\nOn this beach, the water is already filling",
		},
		{
			name: "parenthetical instruction",
			raw:  "(Avoid direct references to the weather.) The poem should evoke a feeling of nostalgia.",
			want: "",
		},
		{
			name: "fake HTTP response",
			raw:  "- Status: 302 Found\n- Location: /blog/posts/some-blog-post/",
			want: "",
		},
		{
			name: "fake HTTP headers block",
			raw:  "- Status: 304\n- Last-Modified: Wed, 31 Dec 2014 22:06:00 +0000\n- ETag: W/\"b7YQ9j6j4HcG3cFhZpQJm4w8w\"\n- Server: cloudflare\n- Date: Wed, 31 Dec 2014 22:06:00 +0000",
			want: "",
		},
		{
			name: "repetition loop collapsed",
			raw:  "A little darker, the night holds its breath.\nA little darker still — the night.\nA little darker still — the night.\nA little darker still — the night.\nA little darker still — the quiet.",
			want: "A little darker, the night holds its breath.\nA little darker still — the night.\nA little darker still — the quiet.",
		},
		{
			name: "question self-assignment",
			raw:  "Question: Craft a sestet (six-line poem with a rhyme scheme of ABABCC) that reflects the journey.\n\nIn the realm of ones and zeros, a silent quest unfolds.",
			want: "In the realm of ones and zeros, a silent quest unfolds.",
		},
		{
			name: "this visitor is instruction echo",
			raw:  "This visitor is new — let this be the starting point of your poem.\nThis visitor is an outsider — let this be the driving force.\nThis visitor is searching — let this be the mood.",
			want: "",
		},
		{
			name: "you could say commentary",
			raw:  "You could say this visitor is a stranger — but a fleeting one.\nYou could say this stranger has been invited — but only to the door.",
			want: "",
		},
		{
			name: "poem with instruction suffix preserved after strip",
			raw:  "A spectral knock upon the door, a brief and bright affair,\nA HEAD request sent through the digital air — let this color the mood subtly,\nTwo visits in the dark of night, a whisper and a prayer.",
			want: "A spectral knock upon the door, a brief and bright affair,\nA HEAD request sent through the digital air\nTwo visits in the dark of night, a whisper and a prayer.",
		},
		{
			name: "no names instruction line",
			raw:  "No names, just a story of this moment where this visitor is.\nThe light fades gently over distant rooftops.",
			want: "The light fades gently over distant rooftops.",
		},
		{
			name: "don't spell instruction line",
			raw:  "Don't spell out this much.\nThe visitor left no fingerprint — only a ghostly digital footprint.",
			want: "The visitor left no fingerprint — only a ghostly digital footprint.",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := cleanPoem(tt.raw, tt.prompt)
			if got != tt.want {
				t.Errorf("cleanPoem() =\n%q\nwant:\n%q", got, tt.want)
			}
		})
	}
}
