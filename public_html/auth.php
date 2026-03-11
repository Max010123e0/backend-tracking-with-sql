<?php
/**
 * Session auth guard — DB-backed, role-aware.
 * Include this at the top of every protected page.
 *
 * Roles:  superadmin  – full access including user management
 *         analyst     – access to sections listed in user_sections
 *         viewer      – saved reports only
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '/var/www/collector.maxk.site/db_config.php';

// ── Core guards ──────────────────────────────────────────────────────────────

/** Redirect to login if not authenticated. */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require a specific role (or superadmin always passes).
 * Sends 403 on failure.
 */
function requireRole(string ...$roles): void
{
    requireLogin();
    $role = currentRole();
    if ($role !== 'superadmin' && !in_array($role, $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

/**
 * Require that the current analyst has access to $section.
 * Superadmins always pass. Viewers always fail (use requireRole for them).
 */
function requireSection(string $section): void
{
    requireLogin();
    if (!canAccessSection($section)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function currentUser(): string
{
    return (string) ($_SESSION['username'] ?? '');
}

function currentRole(): string
{
    return (string) ($_SESSION['role'] ?? '');
}

function currentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/** Returns the list of sections the current user may access. */
function allowedSections(): array
{
    return $_SESSION['sections'] ?? [];
}

/**
 * True if the current user can view $section.
 * Superadmins and full-access analysts return true for all sections.
 */
function canAccessSection(string $section): bool
{
    $role = currentRole();
    if ($role === 'superadmin') return true;
    if ($role === 'analyst') return in_array($section, allowedSections(), true);
    return false; // viewers never access live sections
}

// ── Login / logout ────────────────────────────────────────────────────────────

/**
 * Attempt DB login. Returns true and populates session on success.
 */
function attemptLogin(string $username, string $password): bool
{
    $pdo  = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }

    // Load allowed sections for analysts
    $sections = [];
    if ($row['role'] === 'analyst') {
        $s = $pdo->prepare(
            'SELECT section FROM user_sections WHERE user_id = ?'
        );
        $s->execute([$row['id']]);
        $sections = $s->fetchAll(\PDO::FETCH_COLUMN);
    }

    session_regenerate_id(true);
    $_SESSION['user_id']       = (int) $row['id'];
    $_SESSION['username']      = $row['username'];
    $_SESSION['role']          = $row['role'];
    $_SESSION['sections']      = $sections;
    $_SESSION['authenticated'] = true; // backward-compat flag
    return true;
}
