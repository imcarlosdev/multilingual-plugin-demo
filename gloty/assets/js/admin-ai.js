jQuery(document).ready(function($) {
    const modal = $('#gloty-ai-modal');

    // Open Modal
    $(document).on('click', '.gloty-ai-translate', function(e) {
        e.preventDefault();
        const btn = $(this);
        const postId = btn.data('post-id');
        const targetId = btn.data('target-id');
        const targetLang = btn.data('lang');

        renderModal(postId, targetId, targetLang);
        fetchStrings(postId, targetId, targetLang);
    });

    function renderModal(postId, targetId, targetLang) {
        modal.html(`
            <div class="gloty-ai-content">
                <div class="gloty-ai-header">
                    <h2>AI Translation Review (${targetLang.toUpperCase()})</h2>
                    <div class="gloty-ai-utilities">
                        <button class="button" id="gloty-ai-copy-all" title="Copy all original text to translation boxes">Copy All 📋</button>
                        <button class="gloty-btn-close">&times;</button>
                    </div>
                </div>
                <div class="gloty-ai-body">
                    <div id="gloty-ai-status" style="margin-bottom: 15px; text-align: center; color: #666;">
                        <span class="gloty-loader"></span> Fetching content...
                    </div>
                    <table class="gloty-ai-table" style="display:none;">
                        <thead>
                            <tr>
                                <th>Original Content</th>
                                <th></th>
                                <th>AI Translation</th>
                            </tr>
                        </thead>
                        <tbody id="gloty-ai-strings-list"></tbody>
                    </table>
                </div>
                <div class="gloty-ai-footer">
                    <button class="gloty-btn gloty-btn-secondary" id="gloty-ai-cancel" style="display:none;">Cancel</button>
                    <button class="gloty-btn gloty-btn-primary" id="gloty-ai-translate-btn" style="display:none;">
                        Translate Empty Fields 🤖
                    </button>
                    <button class="gloty-btn gloty-btn-primary" id="gloty-ai-save-btn" style="display:none;">
                        Apply & Save 💾
                    </button>
                </div>
            </div>
        `).fadeIn(200);

        // Close logic
        modal.find('.gloty-btn-close, #gloty-ai-cancel').on('click', function() {
            modal.fadeOut(200);
        });

        // Copy All Logic
        $('#gloty-ai-copy-all').on('click', function() {
            $('.gloty-ai-table tr[data-id]').each(function() {
                const originalText = $(this).find('.content-preview').text();
                $(this).find('.translation-input').val(originalText);
            });
            updateTranslateButtonLabel();
        });
    }

    function fetchStrings(postId, targetId, targetLang) {
        $.post(glotyAi.ajax_url, {
            action: 'gloty_ai_get_strings',
            post_id: postId,
            nonce: glotyAi.nonce
        }, function(response) {
            if (response.success) {
                const strings = response.data;
                const list = $('#gloty-ai-strings-list');
                
                if (strings.length === 0) {
                    $('#gloty-ai-status').html('No translatable content found.');
                    return;
                }

                list.empty();
                strings.forEach(s => {
                    const row = $(`
                        <tr data-id="${s.id}">
                            <td class="original-text">
                                <label style="font-weight:600; font-size:10px; display:block; color:#999; text-transform:uppercase;">${s.label}</label>
                                <div class="content-preview">${escapeHtml(s.value)}</div>
                            </td>
                            <td class="copy-column">
                                <button type="button" class="gloty-copy-btn" title="Copy to translation">→</button>
                            </td>
                            <td class="translated-text">
                                <textarea class="translation-input" placeholder="Awaiting translation..."></textarea>
                            </td>
                        </tr>
                    `);
                    list.append(row);
                });

                updateTranslateButtonLabel();

                $('#gloty-ai-status').hide();
                $('.gloty-ai-table, #gloty-ai-translate-btn, #gloty-ai-cancel').show();

                // Individual Copy Button logic
                $('.gloty-copy-btn').on('click', function() {
                    const row = $(this).closest('tr');
                    const originalText = row.find('.content-preview').text();
                    row.find('.translation-input').val(originalText);
                    updateTranslateButtonLabel();
                });

                // Update label on manual typing too
                $('.translation-input').on('input', function() {
                    updateTranslateButtonLabel();
                });

                // Bind Main Actions
                $('#gloty-ai-translate-btn').off('click').on('click', function() {
                    translateAll(strings, targetLang);
                });

                $('#gloty-ai-save-btn').off('click').on('click', function() {
                    saveTranslations(targetId);
                });
            } else {
                alert(glotyAi.error_msg);
            }
        });
    }

    function updateTranslateButtonLabel() {
        const emptyCount = $('.translation-input').filter(function() { return !$(this).val(); }).length;
        const btn = $('#gloty-ai-translate-btn');
        if (emptyCount === 0) {
            btn.fadeOut(200);
            $('#gloty-ai-save-btn').fadeIn(200);
        } else {
            btn.show().html(`Translate Empty Fields (${emptyCount}) 🤖`);
            $('#gloty-ai-save-btn').show();
        }
    }

    function translateAll(allStrings, targetLang) {
        // Find only strings that are still empty in the UI
        const stringsToTranslate = [];
        $('.gloty-ai-table tr[data-id]').each(function() {
            const row = $(this);
            const input = row.find('.translation-input');
            if (!input.val()) {
                const id = row.data('id');
                const stringObj = allStrings.find(s => s.id === id);
                if (stringObj) {
                    stringsToTranslate.push(stringObj);
                }
            }
        });

        if (stringsToTranslate.length === 0) {
            alert("All fields are already filled!");
            return;
        }

        const btn = $('#gloty-ai-translate-btn');
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<span class="gloty-loader"></span> Translating...');

        $.post(glotyAi.ajax_url, {
            action: 'gloty_ai_translate',
            strings: stringsToTranslate, // Only send empty ones!
            target_lang: targetLang,
            nonce: glotyAi.nonce
        }, function(response) {
            btn.prop('disabled', false).html(originalText);

            if (response.success) {
                const results = response.data;
                results.forEach(item => {
                    $(`tr[data-id="${item.id}"] .translation-input`).val(item.translation);
                });

                updateTranslateButtonLabel();
                btn.hide();
                $('#gloty-ai-save-btn').show();
            } else {
                alert(response.data || glotyAi.error_msg);
            }
        });
    }

    function saveTranslations(targetId) {
        const btn = $('#gloty-ai-save-btn');
        const translations = [];
        
        $('.gloty-ai-table tr[data-id]').each(function() {
            translations.push({
                id: $(this).data('id'),
                translation: $(this).find('.translation-input').val()
            });
        });

        btn.prop('disabled', true).html('<span class="gloty-loader"></span> Saving...');

        $.post(glotyAi.ajax_url, {
            action: 'gloty_ai_save',
            target_id: targetId,
            translations: translations,
            nonce: glotyAi.nonce
        }, function(response) {
            if (response.success) {
                btn.html('Saved! Redirecting...');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                alert(glotyAi.error_msg);
                btn.prop('disabled', false).html('Apply & Save 💾');
            }
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
