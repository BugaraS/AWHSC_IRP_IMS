</main>

<?php if (is_logged_in()): ?>
<div id="aiChatWidget" data-csrf="<?= e(csrf_token()) ?>">
  <button id="aiChatToggle" class="ai-chat-toggle" type="button" aria-label="Open AI Assistant">
    <i class="bi bi-chat-dots-fill"></i>
  </button>
  <div id="aiChatPanel" class="ai-chat-panel d-none">
    <div class="ai-chat-header">
      <div>
        <strong>AWHSC-IRB Assistant</strong>
        <div class="small opacity-75">Helps you navigate the system — not an ethics ruling</div>
      </div>
      <div>
        <button id="aiChatClear" class="btn btn-sm btn-link text-white" type="button" title="Clear conversation"><i class="bi bi-trash"></i></button>
        <button id="aiChatClose" class="btn btn-sm btn-link text-white" type="button" title="Close"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
    <div id="aiChatMessages" class="ai-chat-messages"></div>
    <div id="aiChatTyping" class="ai-chat-typing d-none px-3 pb-1"><em>Assistant is typing…</em></div>
    
                <!-- ፎርም እና የፋይል ፕሪቪዩ (ChatGPT style) -->
    <form id="aiChatForm" class="ai-chat-form p-2 position-relative">
      
               <!-- ፋይሉ ሲመረጥ ከኢንፑቱ በላይ የሚወጣው ፕሪቪዩ ቦክስ -->
      <div id="aiFilePreviewContainer" class="mb-2 d-none">
        <div class="d-inline-flex align-items-center gap-2 bg-light border px-2 py-1 rounded-3 shadow-sm" style="font-size: 12px;">
          <i class="bi bi-file-earmark-pdf-fill text-danger fs-6"></i>
          <span id="aiFileName" class="text-truncate fw-bold text-dark" style="max-width: 180px;"></span>
          <button type="button" id="aiRemoveFile" class="btn btn-sm btn-link text-muted p-0 text-decoration-none ms-1" style="font-size: 16px; line-height: 1;" title="Remove file">&times;</button>
        </div>
      </div>

      <div class="d-flex align-items-center gap-1">
        <!-- the file input-->
        <input type="file" id="aiChatFile" class="d-none">

        <!-- the plus (+) menu-->
        <div class="dropdown">
          <button class="btn btn-sm btn-link text-muted mb-0 px-1 dropdown-toggle no-arrow" type="button" id="chatMenuButton" data-bs-toggle="dropdown" aria-expanded="false" title="Add attachment">
            <i class="bi bi-plus-lg fs-5"></i>
          </button>
          <ul class="dropdown-menu shadow-sm py-2" aria-labelledby="chatMenuButton" style="min-width: 200px; font-size: 13px;">
            <li>
              <label for="aiChatFile" class="dropdown-item d-flex align-items-center gap-2 py-2 mb-0" style="cursor: pointer;">
                <i class="bi bi-paperclip fs-6 text-primary"></i>
                <div>
                  <div class="fw-bold">Upload files</div>
                  <div class="text-muted" style="font-size: 11px;">Upload from computer</div>
                </div>
              </label>
            </li>
          </ul>
        </div>
        
        <input type="text" id="aiChatInput" class="form-control form-control-sm" placeholder="Ask about submitting, reviewing, SOP steps…" autocomplete="off" maxlength="2000">
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send-fill"></i></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<footer class="app-footer text-center py-3">
  <small>Asrat Woldeyes Health Science Campus &middot; Institutional Review Board (AWHSC-IRB) &middot; Debre Berhan University &copy; <?= date('Y') ?></small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/app.js"></script>
<?php if (is_logged_in()): ?>
<script>window.AI_CHAT_BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= BASE_URL ?>assets/js/chat.js"></script>
<?php endif; ?>
</body>
</html>