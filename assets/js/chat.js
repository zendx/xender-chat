jQuery(document).ready(function ($) {
    const $chatBox = $('#xender-chat-box');
    const $chatIcon = $('#xender-chat-icon');
    const $closeBtn = $('#xender-close-btn');

    // Toggle chat visibility
    $chatIcon.on('click', function () {
        $chatBox.fadeToggle();
    });

    $closeBtn.on('click', function () {
        $chatBox.hide(); // Only hide the box, do not reset session
    });

    // Start chat session
    $('#xender-start-btn').on('click', function () {
        const email = $('#xender-email').val();
        const name = $('#xender-name').val();
        if (!email) {
            alert('Please enter a valid email.');
            return;
        }

        // Set hidden fields
        $('input[name="email"]').val(email);
        $('input[name="name"]').val(name);

        // Switch UI
        $('#xender-chat-start').hide();
        $('#xender-chat-session').show();

        // Save to localStorage
        localStorage.setItem('xender_email', email);
        localStorage.setItem('xender_name', name);
        localStorage.setItem('xender_time', Date.now());

        startPolling(email);
    });

    // Handle chat message submission
    $('#xender-chat-form').on('submit', function (e) {
        e.preventDefault();

        const email = $('input[name="email"]').val();
        const formData = new FormData(this);

        $.ajax({
            url: xenderChat.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function (response) {
                if (response.success) {
                    $('#xender-chat-form')[0].reset();
                    fetchChat(email);
                }
            }
        });
    });

    // Fetch chat messages
    function fetchChat(email) {
        $.post(xenderChat.ajax_url, {
            action: 'xender_fetch_chat',
            nonce: xenderChat.nonce,
            email: email
        }, function (response) {
            if (response.success) {
                let html = '';
                response.data.messages.forEach(msg => {
                    const sender = (msg.from === 'admin') ? 'Admin' : 'You';
                    html += `<p><strong>${sender}:</strong> ${msg.message}`;
                    if (msg.file) {
                        html += `<br><a href="${msg.file}" target="_blank">Attachment</a>`;
                    }
                    html += `<br><small>${new Date(msg.time * 1000).toLocaleString()}</small></p><hr>`;
                });
                $('#xender-chat-log').html(html);
            }
        });
    }

    // Start polling every 4 seconds
    function startPolling(email) {
        fetchChat(email);
        setInterval(() => fetchChat(email), 4000);
    }

    // Resume session if valid in localStorage
    const savedEmail = localStorage.getItem('xender_email');
    const savedName = localStorage.getItem('xender_name');
    const savedTime = localStorage.getItem('xender_time');

    if (savedEmail && savedTime && (Date.now() - savedTime < 20 * 60 * 1000)) {
        $('input[name="email"]').val(savedEmail);
        $('input[name="name"]').val(savedName);
        $('#xender-chat-start').hide();
        $('#xender-chat-session').show();
        startPolling(savedEmail);
    } else {
        // Clear expired session
        localStorage.removeItem('xender_email');
        localStorage.removeItem('xender_name');
        localStorage.removeItem('xender_time');
    }
});
