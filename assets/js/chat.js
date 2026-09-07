(function () {
  const widget = document.getElementById('aiChatWidget');
  if (!widget) return;

  const csrfToken = widget.dataset.csrf;
  const toggleBtn = document.getElementById('aiChatToggle');
  const panel = document.getElementById('aiChatPanel');
  const closeBtn = document.getElementById('aiChatClose');
  const clearBtn = document.getElementById('aiChatClear');
  const messagesEl = document.getElementById('aiChatMessages');
  const typingEl = document.getElementById('aiChatTyping');
  const form = document.getElementById('aiChatForm');
  const input = document.getElementById('aiChatInput');
  
  // ፋይል የሚቀበልበት እና የፕሪቪዩ ኤለመንቶች
  const fileInput = document.getElementById('aiChatFile');
  const filePreviewContainer = document.getElementById('aiFilePreviewContainer');
  const fileNameSpan = document.getElementById('aiFileName');
  const removeFileBtn = document.getElementById('aiRemoveFile');

  let historyLoaded = false;

  function scrollToBottom() {
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function renderMessage(role, content) {
    const wrap = document.createElement('div');
    wrap.className = 'ai-msg ai-msg-' + (role === 'user' ? 'user' : 'assistant');
    wrap.innerHTML = escapeHtml(content).replace(/\n/g, '<br>');
    messagesEl.appendChild(wrap);
    scrollToBottom();
  }

  function renderError(message) {
    const wrap = document.createElement('div');
    wrap.className = 'ai-msg ai-msg-error';
    wrap.textContent = message;
    messagesEl.appendChild(wrap);
    scrollToBottom();
  }

  async function loadHistory() {
    if (historyLoaded) return;
    historyLoaded = true;
    try {
      const res = await fetch(window.AI_CHAT_BASE_URL + 'chat/history.php');
      const data = await res.json();
      if (data.messages && data.messages.length) {
        data.messages.forEach(m => renderMessage(m.role, m.content));
      } else {
        renderMessage('assistant', "Hi! I'm the AWHSC-IRB Assistant. Ask me about submitting a protocol, the Annex 1 checklist, review steps, or any other part of this system.");
      }
    } catch (e) {
      renderError('Could not load your conversation history.');
    }
  }

  // ፋይል ሲመረጥ ፕሪቪዩውን ከላይ ማሳየት
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      if (fileInput.files.length > 0) {
        if (fileNameSpan) fileNameSpan.textContent = fileInput.files[0].name;
        if (filePreviewContainer) filePreviewContainer.classList.remove('d-none');
      }
    });
  }

  // የ 'X' ቁልፍን ሲጫኑ ፋይሉን ሰርዞ ፕሪቪዩውን መደበቅ
  if (removeFileBtn) {
    removeFileBtn.addEventListener('click', () => {
      if (fileInput) fileInput.value = '';
      if (filePreviewContainer) filePreviewContainer.classList.add('d-none');
    });
  }

  toggleBtn.addEventListener('click', () => {
    panel.classList.toggle('d-none');
    if (!panel.classList.contains('d-none')) {
      loadHistory();
      input.focus();
    }
  });
  closeBtn.addEventListener('click', () => panel.classList.add('d-none'));

  clearBtn.addEventListener('click', async () => {
    if (!confirm('Clear this conversation?')) return;
    try {
      await fetch(window.AI_CHAT_BASE_URL + 'chat/clear.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      messagesEl.innerHTML = '';
      historyLoaded = false;
      loadHistory();
    } catch (e) {
      renderError('Could not clear the conversation.');
    }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    const hasFile = fileInput && fileInput.files.length > 0;

    if (!text && !hasFile) return;

    // 1. ፎርም ዳታ ማዘጋጀት (ፋይሉ ከመጥፋቱ በፊት)
    const formData = new FormData();
    formData.append('message', text);
    if (hasFile) {
      formData.append('attachment', fileInput.files[0]);
    }

    // 2. የተጠቃሚውን መልዕክት በቻት ሣጥኑ ውስጥ ማሳየት
    let displayMsg = text;
    if (hasFile) {
      displayMsg += ` [Attached: ${fileInput.files[0].name}]`;
    }
    renderMessage('user', displayMsg);

    // 3. የጽሁፍ ኢንፑቱን ማጽዳት (የፋይል ኢንፑቱ ግን ዳታው እስከሚላክ ይቀመጣል)
    input.value = '';
    typingEl.classList.remove('d-none');

    try {
      const res = await fetch(window.AI_CHAT_BASE_URL + 'chat/send.php', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': csrfToken,
        },
        body: formData, // ፋይሉ በትክክል ወደ ሰርቨር ይላካል
      });
      const data = await res.json();
      typingEl.classList.add('d-none');
      
      // 4. መልዕክቱ እና ፋይሉ በተሳካ ሁኔታ ከተላኩ በኋላ የፋይል ኢንፑቱን እና ፕሪቪዩውን እናጸዳለን
      if (fileInput) fileInput.value = '';
      if (filePreviewContainer) filePreviewContainer.classList.add('d-none');

      if (data.error || (data.success === false)) {
        renderError(data.error || data.reply || 'An error occurred.');
      } else {
        renderMessage('assistant', data.reply);
      }
    } catch (err) {
      typingEl.classList.add('d-none');
      if (fileInput) fileInput.value = '';
      if (filePreviewContainer) filePreviewContainer.classList.add('d-none');
      renderError('Network error — could not reach the assistant.');
    }
  });
})();