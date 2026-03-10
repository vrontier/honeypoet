package main

import "testing"

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
