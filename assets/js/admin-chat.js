jQuery(document).ready(function ($) {
    const chatLog = $('#xender-admin-chat-log');
    const replyContainer = $('#xender-reply-form-container');
    const clientSelector = $('#xender-chat-select');
    const currentClient = $('#xender-current-client');
    let interval;
    let replyFormRendered = false;

    function renderReplyForm(email) {
        if (replyFormRendered) return;
        replyFormRendered = true;

        const formHtml = `
            <form method="post" action="${xenderChat.admin_post_url}" enctype="multipart/form-data">
                <input type="hidden" name="action" value="xender_send_reply">
                <input type="hidden" name="_wpnonce" value="${xenderChat.reply_nonce}">
                <input type="hidden" name="email" value="${email}">
                <textarea name="reply_message" placeholder="Type your reply..." required></textarea><br>
                <input type="file" name="attachment" accept="image/*,.pdf"><br>
                <button class="button button-primary" type="submit">Send Reply</button>
            </form>
        `;
        replyContainer.html(formHtml);
    }

    function fetchChat(email) {
        $.post(xenderChat.ajax_url, {
            action: 'xender_fetch_chat',
            nonce: xenderChat.nonce,
            email: email
        }, function (response) {
            if (response.success) {
                const messages = response.data.messages || [];
                chatLog.empty();
                messages.forEach(msg => {
                    let line = `<p><strong>${msg.name}:</strong> ${msg.message} <em>(${new Date(msg.time * 1000).toLocaleTimeString()})</em>`;
                    if (msg.file) {
                        line += `<br><a href="${msg.file}" target="_blank">[File]</a>`;
                    }
                    line += '</p>';
                    chatLog.append(line);
                });

                renderReplyForm(email); // Render form after chat loads
            }
        });
    }

    clientSelector.on('change', function () {
        const email = $(this).val();
        currentClient.val(email);
        replyFormRendered = false; // reset to allow new form render
        fetchChat(email);

        clearInterval(interval);
        interval = setInterval(() => {
            const email = currentClient.val();
            if (email) fetchChat(email);
        }, 5000);
    });

    // Trigger the first chat load
    clientSelector.trigger('change');
});
