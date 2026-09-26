<?php
function sh(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function settings_card_open(string $title, ?string $desc = null): void
{
    echo '<div class="settings-card"><div class="settings-card-head"><h2 class="settings-card-title">' . sh($title) . '</h2>';
    if ($desc) {
        echo '<p class="settings-card-desc">' . sh($desc) . '</p>';
    }
    echo '</div><div class="settings-card-body">';
}

function settings_card_close(): void
{
    echo '</div></div>';
}

function settings_toggle(string $name, string $label, bool $on, ?string $hint = null): void
{
    echo '<label class="settings-toggle">';
    echo '<input type="checkbox" name="' . sh($name) . '" value="1"' . ($on ? ' checked' : '') . '>';
    echo '<span class="settings-toggle-ui"></span>';
    echo '<span class="settings-toggle-label">' . sh($label);
    if ($hint) {
        echo ' <small class="text-muted">' . sh($hint) . '</small>';
    }
    echo '</span></label>';
}
