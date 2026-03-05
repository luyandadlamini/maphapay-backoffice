document.addEventListener('alpine:init', () => {
    Alpine.data('foodoChat', () => ({
        messages: [],
        input: '',
        sending: false,

        init() {
            // Load initial demo messages
            this.messages = [
                {
                    role: 'ai',
                    content: `<p class="mb-3 font-medium">Hello! I'm your AI advisor for Foodo Insights. I can help you analyze:</p>
                        <ul class="list-disc pl-5 space-y-1 mb-4 text-stone-600">
                            <li>Sales performance for any period</li>
                            <li>Marketing campaign effectiveness</li>
                            <li>Channel performance comparisons</li>
                            <li>Trends and recommendations</li>
                        </ul>
                        <p class="text-stone-500 italic text-xs">Try asking: "What were the total sales in January 2025?"</p>`,
                    time: '10:23 AM',
                },
            ];
            this.$nextTick(() => this.scrollToBottom());
        },

        async send() {
            if (!this.input.trim() || this.sending) return;

            const userMsg = this.input.trim();
            this.input = '';
            this.sending = true;

            // Add user message
            this.messages.push({
                role: 'user',
                content: `<p class="font-medium">${this.escapeHtml(userMsg)}</p>`,
                time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
            });
            this.$nextTick(() => this.scrollToBottom());

            try {
                const response = await fetch('/foodo/chat/message', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ message: userMsg }),
                });

                const data = await response.json();

                this.messages.push({
                    role: 'ai',
                    content: data.reply || '<p>I couldn\'t process that request. Please try again.</p>',
                    time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                });
            } catch {
                this.messages.push({
                    role: 'ai',
                    content: '<p>Sorry, something went wrong. Please try again later.</p>',
                    time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                });
            }

            this.sending = false;
            this.$nextTick(() => this.scrollToBottom());
        },

        scrollToBottom() {
            const container = document.getElementById('chat-container');
            if (container) container.scrollTop = container.scrollHeight;
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
    }));
});
