<?php
// Help Center module: schema, helpers and the safe article renderer.
// This file is NEW. It only reuses helpers from config.php ($pdo, e(), csrf_*, flash(), current_user() ...).
// It never reads or writes any existing table except SELECT id/full_name from `users` for display.

require_once __DIR__ . '/../config.php';

const HELP_UPLOAD_SUBDIR = 'help/';                       // inside uploads/
const HELP_TICKET_STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'answered' => 'Answered', 'closed' => 'Closed'];
const HELP_TICKET_TYPES = ['question' => 'Question', 'concern' => 'Concern', 'technical' => 'Technical issue'];
const HELP_TICKET_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];

/* ------------------------------------------------------------------ schema */

// One CREATE TABLE per entry. help_center.sql is generated from this same list.
function help_ddl() {
    return [
'help_meta' => "CREATE TABLE IF NOT EXISTS help_meta (
  meta_key VARCHAR(40) NOT NULL PRIMARY KEY,
  meta_value VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'help_categories' => "CREATE TABLE IF NOT EXISTS help_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  icon VARCHAR(40) NOT NULL DEFAULT 'journal-text',
  description VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'help_articles' => "CREATE TABLE IF NOT EXISTS help_articles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NULL,
  title VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  summary VARCHAR(300) NULL,
  body MEDIUMTEXT NOT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  views INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ha_pub (is_published, category_id),
  CONSTRAINT fk_ha_cat FOREIGN KEY (category_id) REFERENCES help_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'help_faqs' => "CREATE TABLE IF NOT EXISTS help_faqs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NULL,
  question VARCHAR(255) NOT NULL,
  answer TEXT NOT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_hf_cat FOREIGN KEY (category_id) REFERENCES help_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'help_tutorials' => "CREATE TABLE IF NOT EXISTS help_tutorials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  description VARCHAR(300) NULL,
  icon VARCHAR(40) NOT NULL DEFAULT 'mortarboard',
  est_minutes INT NOT NULL DEFAULT 3,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'help_tutorial_steps' => "CREATE TABLE IF NOT EXISTS help_tutorial_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tutorial_id INT NOT NULL,
  step_no INT NOT NULL DEFAULT 1,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  target_module VARCHAR(30) NULL,
  target_selector VARCHAR(190) NULL,
  demo_action VARCHAR(500) NULL,
  image VARCHAR(255) NULL,
  INDEX idx_hts (tutorial_id, step_no),
  CONSTRAINT fk_hts_tut FOREIGN KEY (tutorial_id) REFERENCES help_tutorials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'help_tickets' => "CREATE TABLE IF NOT EXISTS help_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_no VARCHAR(20) NOT NULL UNIQUE,
  public_token CHAR(32) NOT NULL UNIQUE,
  user_id INT NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL,
  type ENUM('question','concern','technical') NOT NULL DEFAULT 'question',
  priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  subject VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open','in_progress','answered','closed') NOT NULL DEFAULT 'open',
  ip_address VARCHAR(45) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ht_status (status, created_at),
  INDEX idx_ht_user (user_id),
  INDEX idx_ht_ip (ip_address, created_at),
  CONSTRAINT fk_ht_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'help_ticket_replies' => "CREATE TABLE IF NOT EXISTS help_ticket_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  user_id INT NULL,
  is_staff TINYINT(1) NOT NULL DEFAULT 0,
  author_name VARCHAR(100) NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_htr_ticket (ticket_id, created_at),
  CONSTRAINT fk_htr_ticket FOREIGN KEY (ticket_id) REFERENCES help_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_htr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

// Creates the help_* tables (only if missing) and loads the starter content once.
// Safe to call on every request: after the first run it costs a single tiny query.
function help_ensure_schema() {
    global $pdo; static $done = false;
    if ($done) return; $done = true;
    try { $pdo->query('SELECT 1 FROM help_meta LIMIT 1'); }
    catch (Throwable $e) {
        try { foreach (help_ddl() as $sql) $pdo->exec($sql); }
        catch (Throwable $e2) {
            exit('The Help Center tables could not be created automatically (' . htmlspecialchars($e2->getMessage()) . '). Import help_center.sql in phpMyAdmin, then reload.');
        }
    }
    // Seed once. INSERT IGNORE on the flag makes this race-safe; deleting all content later never re-seeds.
    $flag = $pdo->prepare("INSERT IGNORE INTO help_meta (meta_key, meta_value) VALUES ('seeded', ?)");
    $flag->execute([date('c')]);
    if ($flag->rowCount() === 1) {
        require_once __DIR__ . '/help_seed.php';
        try { help_seed($pdo); }
        catch (Throwable $e) { $pdo->exec("DELETE FROM help_meta WHERE meta_key='seeded'"); throw $e; }
    }
}

/* ------------------------------------------------------------------ small helpers */

function help_slug($text) {
    $s = strtolower(trim((string)$text));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? mb_substr($s, 0, 100) : 'item';
}

// Returns a slug that is not used by another row of $table ($table is NOT user input).
function help_unique_slug($table, $text, $ignoreId = 0) {
    global $pdo;
    if (!in_array($table, ['help_articles', 'help_categories', 'help_tutorials'], true)) throw new InvalidArgumentException('Bad table.');
    $base = help_slug($text); $slug = $base; $n = 2;
    $st = $pdo->prepare("SELECT 1 FROM $table WHERE slug=? AND id<>?");
    while (true) {
        $st->execute([$slug, $ignoreId]);
        if (!$st->fetchColumn()) return $slug;
        $slug = $base . '-' . $n++;
    }
}

function help_like($s) { return '%' . addcslashes($s, '\\%_') . '%'; }

function help_icon($name, $fallback = 'journal-text') { return preg_match('/^[a-z0-9-]{2,40}$/', (string)$name) ? $name : $fallback; }

function help_ago($ts) {
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 86400 * 14) return floor($d / 86400) . ' d ago';
    return date('M d, Y', strtotime($ts));
}

function help_ticket_badge($status) {
    $c = ['open' => 'warning', 'in_progress' => 'info', 'answered' => 'success', 'closed' => 'muted'][$status] ?? 'muted';
    return '<span class="badge badge-' . $c . '">' . e(HELP_TICKET_STATUSES[$status] ?? $status) . '</span>';
}

function help_categories($onlyActive = true) {
    global $pdo;
    return $pdo->query('SELECT * FROM help_categories' . ($onlyActive ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order, name')->fetchAll();
}

/* ------------------------------------------------------------------ image upload (own folder, own checks) */

function help_upload_image($file) {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 4 * 1024 * 1024) throw new Exception('Image upload failed. Maximum size is 4 MB.');
    $info = @getimagesize($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
    if (!$ext) throw new Exception('Only JPG, PNG, WEBP or GIF images are allowed.');
    $dir = UPLOAD_DIR . HELP_UPLOAD_SUBDIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new Exception('Could not create the uploads/help folder. Check folder permissions.');
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) throw new Exception('Could not save the image. Check the uploads/help folder permissions.');
    return HELP_UPLOAD_SUBDIR . $name;   // path relative to uploads/
}
function help_image_ok($rel) { return is_string($rel) && preg_match('#^help/[a-f0-9]{20}\.(jpg|png|webp|gif)$#', $rel); }
function help_delete_image($rel) { if (help_image_ok($rel) && is_file(UPLOAD_DIR . $rel)) @unlink(UPLOAD_DIR . $rel); }

/* ------------------------------------------------------------------ safe article renderer
   Authors write plain text with a few marks; everything is HTML-escaped FIRST, so no script or tag can get through.
     ## Heading        ### Sub-heading       - bullet        1. numbered step      > Tip or note
     **bold**   `code`   [link text](help_faq.php)   ![caption](help/abc....png)   blank line = new paragraph          */

function help_inline($s) {
    $s = e($s);
    $s = preg_replace_callback('/!\[([^\]]*)\]\((help\/[a-f0-9]{20}\.(?:jpg|png|webp|gif))\)/', fn($m) =>
        '<figure class="hlp-figure"><img src="uploads/' . $m[2] . '" alt="' . $m[1] . '" loading="lazy"><figcaption>' . $m[1] . '</figcaption></figure>', $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = html_entity_decode($m[2], ENT_QUOTES);
        $ok = preg_match('#^https?://#i', $url) || preg_match('#^[a-z0-9_]+\.php(\?[a-z0-9_=&%.\-]*)?(\#[a-z0-9_-]*)?$#i', $url);
        if (!$ok) return $m[0];
        $ext = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' . e($url) . '"' . $ext . '>' . $m[1] . '</a>';
    }, $s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    return $s;
}

function help_render($text) {
    $lines = preg_split('/\R/', (string)$text);
    $out = ''; $list = null; $para = [];
    $flushP = function () use (&$out, &$para) { if ($para) { $out .= '<p>' . help_inline(implode(' ', $para)) . '</p>'; $para = []; } };
    $closeL = function () use (&$out, &$list) { if ($list) { $out .= "</$list>"; $list = null; } };
    foreach ($lines as $ln) {
        $t = trim($ln);
        if ($t === '') { $flushP(); $closeL(); continue; }
        if (preg_match('/^(#{2,3})\s+(.+)$/', $t, $m)) { $flushP(); $closeL(); $h = strlen($m[1]) + 1; $out .= "<h$h>" . help_inline($m[2]) . "</h$h>"; continue; }
        if (preg_match('/^[-*]\s+(.+)$/', $t, $m)) { $flushP(); if ($list !== 'ul') { $closeL(); $out .= '<ul>'; $list = 'ul'; } $out .= '<li>' . help_inline($m[1]) . '</li>'; continue; }
        if (preg_match('/^\d+[.)]\s+(.+)$/', $t, $m)) { $flushP(); if ($list !== 'ol') { $closeL(); $out .= '<ol>'; $list = 'ol'; } $out .= '<li>' . help_inline($m[1]) . '</li>'; continue; }
        if (preg_match('/^>\s?(.+)$/', $t, $m)) { $flushP(); $closeL(); $out .= '<div class="hlp-note"><i class="bi bi-lightbulb"></i><div>' . help_inline($m[1]) . '</div></div>'; continue; }
        if (preg_match('/^!\[.*\]\(.*\)$/', $t)) { $flushP(); $closeL(); $out .= help_inline($t); continue; }
        $closeL(); $para[] = $t;
    }
    $flushP(); $closeL();
    return $out;
}

/* ------------------------------------------------------------------ search (articles, FAQs, tutorials) */

function help_search($q, $limit = 30) {
    global $pdo;
    $like = help_like($q); $res = ['articles' => [], 'faqs' => [], 'tutorials' => []];
    $st = $pdo->prepare("SELECT a.id,a.title,a.slug,a.summary,c.name cat FROM help_articles a LEFT JOIN help_categories c ON c.id=a.category_id
        WHERE a.is_published=1 AND (c.id IS NULL OR c.is_active=1) AND (a.title LIKE ? OR a.summary LIKE ? OR a.body LIKE ?)
        ORDER BY (a.title LIKE ?) DESC, a.views DESC LIMIT " . (int)$limit);
    $st->execute([$like, $like, $like, $like]); $res['articles'] = $st->fetchAll();
    $st = $pdo->prepare("SELECT id,question,answer FROM help_faqs WHERE is_published=1 AND (question LIKE ? OR answer LIKE ?) ORDER BY (question LIKE ?) DESC LIMIT " . (int)$limit);
    $st->execute([$like, $like, $like]); $res['faqs'] = $st->fetchAll();
    $st = $pdo->prepare("SELECT id,title,slug,description FROM help_tutorials WHERE is_published=1 AND (title LIKE ? OR description LIKE ?) ORDER BY sort_order LIMIT " . (int)$limit);
    $st->execute([$like, $like]); $res['tutorials'] = $st->fetchAll();
    return $res;
}

function help_ticket_no() {
    global $pdo;
    for ($i = 0; $i < 6; $i++) {
        $no = 'HLP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $st = $pdo->prepare('SELECT 1 FROM help_tickets WHERE ticket_no=?'); $st->execute([$no]);
        if (!$st->fetchColumn()) return $no;
    }
    throw new Exception('Could not create a ticket number. Please try again.');
}

// Plain-text length check that works with multibyte text
function help_len_ok($s, $min, $max) { $n = mb_strlen($s); return $n >= $min && $n <= $max; }
