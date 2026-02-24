package main

import "testing"

func TestCleanPoem(t *testing.T) {
	tests := []struct {
		name string
		raw  string
		want string
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
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := cleanPoem(tt.raw)
			if got != tt.want {
				t.Errorf("cleanPoem() =\n%q\nwant:\n%q", got, tt.want)
			}
		})
	}
}
