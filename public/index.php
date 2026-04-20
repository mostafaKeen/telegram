<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/crest.php');

// Allow embedding in Bitrix24
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: frame-ancestors 'self' *.bitrix24.com *.bitrix24.info");
header('P3P: CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keen Telegram Dashboard</title>
    <!-- Bitrix24 SDK -->
    <script src="//api.bitrix24.com/api/v1/"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>Keen Telegram</h1>
                <div class="search-bar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="chatSearch" placeholder="Search chats...">
                </div>
            </div>
            <div class="chat-list" id="chatList">
                <!-- Chats populated by JS -->
            </div>
        </aside>

        <!-- Main Content -->
        <main class="chat-main" id="chatMain">
            <div id="welcomeScreen" class="welcome-screen">
                <i class="fab fa-telegram-plane"></i>
                <h2>Welcome to your Bridge</h2>
                <p>Select a chat from the sidebar to start messaging.</p>
            </div>

            <div id="chatContent" style="display: none; height: 100%; flex-direction: column;">
                <header class="chat-header">
                    <div style="display: flex; align-items: center;">
                        <div class="avatar" id="currentAvatar"></div>
                        <div>
                            <div class="chat-name" id="currentChatName"></div>
                            <div class="chat-time" id="currentChatStatus">online</div>
                        </div>
                    </div>
                    <div class="header-actions">
                        <button class="btn-icon"><i class="fas fa-ellipsis-v"></i></button>
                    </div>
                </header>

                <div class="messages-container" id="messagesContainer">
                    <!-- Messages populated by JS -->
                </div>

                <div class="input-area">
                    <button class="btn-icon" id="attachBtn"><i class="fas fa-paperclip"></i></button>
                    <input type="file" id="fileInput" style="display: none;">
                    
                    <div class="input-container">
                        <input type="text" id="messageInput" placeholder="Type a message...">
                        <button class="btn-icon" id="emojiBtn"><i class="far fa-smile"></i></button>
                    </div>

                    <div id="recordingUi" class="recording-ui">
                        <i class="fas fa-microphone"></i>
                        <span id="recordingTimer">00:00</span>
                        <button class="btn-icon" id="cancelRecordBtn" style="color: white;"><i class="fas fa-times"></i></button>
                    </div>

                    <button class="btn-icon" id="recordBtn"><i class="fas fa-microphone"></i></button>
                    <button class="btn-icon btn-send" id="sendBtn" style="display: none;"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>
        </main>
    </div>

    <!-- Lightbox -->
    <div id="lightbox" style="display:none; position:fixed; z-index:100; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); align-items:center; justify-content:center;">
        <img id="lightboxImg" style="max-width:90%; max-height:90%; border-radius:8px;">
        <button onclick="document.getElementById('lightbox').style.display='none'" style="position:absolute; top:20px; right:20px; background:none; border:none; color:white; font-size:30px; cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>

    <script>
        let currentChatId = null;
        let lastMessageId = 0;
        let lastSidebarMessageId = 0;
        let mediaRecorder = null;
        let audioChunks = [];

        document.addEventListener('DOMContentLoaded', () => {
            if (typeof BX24 !== 'undefined') {
                BX24.init(function(){
                    console.log('Bitrix24 SDK Initialized');
                });
            }
            loadChats();
            startGlobalStream();

            // Search functionality
            document.getElementById('chatSearch').addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('.chat-item').forEach(item => {
                    const name = item.querySelector('.chat-name').textContent.toLowerCase();
                    item.style.display = name.includes(term) ? 'flex' : 'none';
                });
            });

            // Input handlers
            const messageInput = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendBtn');
            const recordBtn = document.getElementById('recordBtn');

            messageInput.addEventListener('input', () => {
                if (messageInput.value.trim().length > 0) {
                    sendBtn.style.display = 'flex';
                    recordBtn.style.display = 'none';
                } else {
                    sendBtn.style.display = 'none';
                    recordBtn.style.display = 'flex';
                }
            });

            sendBtn.addEventListener('click', sendMessage);
            messageInput.addEventListener('keypress', (e) => { if(e.key === 'Enter') sendMessage(); });

            // File upload
            document.getElementById('attachBtn').addEventListener('click', () => {
                document.getElementById('fileInput').click();
            });

            document.getElementById('fileInput').addEventListener('change', (e) => {
                if (e.target.files.length > 0) {
                    handleFileUpload(e.target.files[0]);
                }
            });

            // Voice Recording
            recordBtn.addEventListener('click', startRecording);
            document.getElementById('cancelRecordBtn').addEventListener('click', stopRecording);
        });

        async function loadChats() {
            const res = await fetch('api.php?action=list_chats');
            const data = await res.json();
            if (data.success) {
                const list = document.getElementById('chatList');
                list.innerHTML = '';
                data.chats.forEach(chat => {
                    const item = document.createElement('div');
                    item.className = 'chat-item';
                    item.onclick = () => selectChat(chat);
                    item.dataset.id = chat.telegram_chat_id;
                    
                    const initials = (chat.first_name || 'U').charAt(0).toUpperCase();
                    const avatarContent = chat.photo_url ? `<img src="${chat.photo_url}">` : initials;
                    
                    item.innerHTML = `
                        <div class="avatar">${avatarContent}</div>
                        <div class="chat-info">
                            <div class="chat-name">${chat.first_name || 'User'} ${chat.last_name || ''}</div>
                            <div class="chat-last-message">${chat.last_message || 'No messages yet'}</div>
                        </div>
                    `;
                    list.appendChild(item);
                });
            }
        }

        async function selectChat(chat) {
            currentChatId = chat.telegram_chat_id;
            document.getElementById('welcomeScreen').style.display = 'none';
            document.getElementById('chatContent').style.display = 'flex';
            
            // Highlight active
            document.querySelectorAll('.chat-item').forEach(i => i.classList.remove('active'));
            const activeItem = document.querySelector(`.chat-item[data-id="${chat.telegram_chat_id}"]`);
            if (activeItem) activeItem.classList.add('active');

            // Header info
            document.getElementById('currentChatName').textContent = `${chat.first_name || 'User'} ${chat.last_name || ''}`;
            const initials = (chat.first_name || 'U').charAt(0).toUpperCase();
            document.getElementById('currentAvatar').innerHTML = chat.photo_url ? `<img src="${chat.photo_url}">` : initials;

            // Load messages
            const res = await fetch(`api.php?action=get_messages&chat_id=${currentChatId}`);
            const data = await res.json();
            const container = document.getElementById('messagesContainer');
            container.innerHTML = '';
            
            if (data.success) {
                lastMessageId = 0;
                data.messages.forEach(appendMessage);
            }
            
            scrollToBottom();
            startChatStream(chat.telegram_chat_id);
        }

        function appendMessage(msg) {
            const container = document.getElementById('messagesContainer');
            const bubble = document.createElement('div');
            bubble.className = `message-bubble message-${msg.direction.toLowerCase()}`;
            bubble.dataset.id = msg.id;

            let content = '';
            if (msg.media_type === 'photo') {
                content += `<div class="message-media"><img src="uploads/${msg.media_path}" onclick="openLightbox(this.src)"></div>`;
            } else if (msg.media_type === 'voice') {
                content += `<div class="message-media"><audio controls src="uploads/${msg.media_path}" style="width: 200px;"></audio></div>`;
            } else if (msg.media_type === 'document') {
                content += `<div class="message-media"><a href="uploads/${msg.media_path}" target="_blank" style="color: var(--primary); text-decoration: none;"><i class="fas fa-file"></i> ${msg.media_path}</a></div>`;
            }

            if (msg.text) {
                content += `<div>${escapeHtml(msg.text)}</div>`;
            }

            const time = new Date(msg.timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            
            bubble.innerHTML = content + `
                <div class="message-footer">
                    <div class="message-time">${time}</div>
                    <div class="message-status ${msg.id === 'temp' ? 'loading' : ''}">
                        <i class="fas ${msg.id === 'temp' ? 'fa-circle-notch' : 'fa-check-double'}"></i>
                    </div>
                </div>
            `;
            
            container.appendChild(bubble);
            if (msg.id !== 'temp') {
                lastMessageId = Math.max(lastMessageId, msg.id);
            }
            scrollToBottom();
        }

        function startGlobalStream() {
            setInterval(async () => {
                try {
                    const res = await fetch(`stream.php?last_id=${lastSidebarMessageId}`);
                    const data = await res.json();
                    if (data.success && data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            updateSidebar(msg);
                            lastSidebarMessageId = Math.max(lastSidebarMessageId, msg.id);
                        });
                    }
                } catch (e) {}
            }, 5000);
        }

        function updateSidebar(msg) {
            const chatItem = document.querySelector(`.chat-item[data-id="${msg.telegram_chat_id}"]`);
            if (chatItem) {
                chatItem.querySelector('.chat-last-message').textContent = msg.text || `[${msg.media_type}]`;
                const list = document.getElementById('chatList');
                list.prepend(chatItem);
            } else {
                loadChats(); 
            }
        }

        function startChatStream(chatId) {
            if (window.chatInterval) clearInterval(window.chatInterval);
            window.chatInterval = setInterval(async () => {
                if (currentChatId !== chatId) return;
                try {
                    const res = await fetch(`stream.php?chat_id=${chatId}&last_id=${lastMessageId}`);
                    const data = await res.json();
                    if (data.success && data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            // Deduplicate: if we have a temp message with same text, remove temp
                            const temps = document.querySelectorAll('.message-bubble[data-id="temp"]');
                            temps.forEach(t => {
                                if (t.textContent.includes(msg.text)) t.remove();
                            });
                            appendMessage(msg);
                        });
                    }
                } catch (e) {}
            }, 3000);
        }

        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const text = input.value.trim();
            if (!text || !currentChatId) return;

            input.value = '';
            document.getElementById('sendBtn').style.display = 'none';
            document.getElementById('recordBtn').style.display = 'flex';

            const formData = new FormData();
            formData.append('chat_id', currentChatId);
            formData.append('text', text);

            // Optimistic UI
            appendMessage({
                id: 'temp',
                direction: 'OUT',
                text: text,
                timestamp: Math.floor(Date.now() / 1000),
                media_type: 'text'
            });

            const res = await fetch('api.php?action=send_message', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (!data.success) {
                alert("Error: " + data.error);
                // Remove temp on failure
                const temp = document.querySelector('.message-bubble[data-id="temp"]');
                if (temp) temp.remove();
            }
        }

        async function startRecording() {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];
            mediaRecorder.ondataavailable = (e) => audioChunks.push(e.data);
            mediaRecorder.onstop = uploadVoice;
            mediaRecorder.start();
            document.getElementById('recordBtn').style.display = 'none';
            document.getElementById('recordingUi').style.display = 'flex';
            startTimer();
        }

        function stopRecording() {
            if (mediaRecorder) {
                mediaRecorder.stop();
                document.getElementById('recordingUi').style.display = 'none';
                document.getElementById('recordBtn').style.display = 'flex';
                stopTimer();
            }
        }

        async function uploadVoice() {
            const blob = new Blob(audioChunks, { type: 'audio/ogg' });
            const formData = new FormData();
            formData.append('chat_id', currentChatId);
            formData.append('file', blob, 'voice.ogg');
            formData.append('is_voice', '1');
            await fetch('api.php?action=send_message', { method: 'POST', body: formData });
        }

        async function handleFileUpload(file) {
            const formData = new FormData();
            formData.append('chat_id', currentChatId);
            formData.append('file', file);
            await fetch('api.php?action=send_message', { method: 'POST', body: formData });
            document.getElementById('fileInput').value = '';
        }

        function scrollToBottom() {
            const container = document.getElementById('messagesContainer');
            container.scrollTop = container.scrollHeight;
        }

        function openLightbox(src) {
            document.getElementById('lightboxImg').src = src;
            document.getElementById('lightbox').style.display = 'flex';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        let timerInterval;
        function startTimer() {
            let sec = 0;
            const timer = document.getElementById('recordingTimer');
            timerInterval = setInterval(() => {
                sec++;
                const m = Math.floor(sec / 60);
                const s = sec % 60;
                timer.textContent = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
            }, 1000);
        }

        function stopTimer() {
            clearInterval(timerInterval);
            document.getElementById('recordingTimer').textContent = '00:00';
        }
    </script>
</body>
</html>
