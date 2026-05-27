<?php

declare(strict_types=1);

namespace Gisl\Sdk;

use Gisl\Sdk\Errors\GislConfigError;

/**
 * Credential + endpoint resolution for the ergonomic-layer
 * {@see Gisl::create()} factory. Implements an AWS-style short-circuiting
 * chain:
 *
 *   API key:    explicit arg → `GISL_API_KEY` env → `~/.gisl/credentials` profile
 *   Endpoint:   explicit arg → `GISL_BASE_URL` / `GISL_ENVIRONMENT` env → prod default
 *
 * Sources are tried in order; the first hit wins. Malformed profile files
 * surface as {@see GislConfigError} naming the offending path / profile /
 * line number — credential VALUES MUST NOT appear in error messages.
 *
 * Mirrors `packages/typescript/src/credentials.ts`.
 */
final class Credentials
{
    public const GISL_API_KEY_ENV = 'GISL_API_KEY';
    public const GISL_BASE_URL_ENV = 'GISL_BASE_URL';
    public const GISL_ENVIRONMENT_ENV = 'GISL_ENVIRONMENT';

    /**
     * Named environments → base URLs. Kept colocated with the resolver so
     * the mapping table doesn't leak into {@see Gisl}.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT_ENDPOINTS = [
        'prod' => 'https://api.giveitsmaller.com',
        'staging' => 'https://api.staging.giveitsmaller.com',
    ];

    public const DEFAULT_ENDPOINT = 'https://api.giveitsmaller.com';

    public const DEFAULT_PROFILE = 'default';

    /**
     * Resolve the API key via the credential chain. Returns the resolved
     * key, or `null` if no source produced one. The ergonomic-layer
     * factory is responsible for deciding whether `null` is an error
     * (default: yes, throw {@see \Gisl\Sdk\Errors\GislMissingCredentialsError})
     * or acceptable (anonymous / cookie-mode).
     *
     * Mirrors `resolveApiKey` in `packages/typescript/src/credentials.ts:75-113`.
     */
    public static function resolveApiKey(
        ?string $apiKey = null,
        ?string $profile = null,
        ?string $profilePath = null,
    ): ?string {
        // 1. Explicit wins (non-empty string).
        if ($apiKey !== null && $apiKey !== '') {
            return $apiKey;
        }

        // 2. Environment variable.
        $envKey = self::readEnv(self::GISL_API_KEY_ENV);
        if ($envKey !== null) {
            return $envKey;
        }

        // 3. Shared-config profile (`~/.gisl/credentials`).
        $path = $profilePath ?? self::defaultProfilePath();
        if ($path === null) {
            return null;
        }

        $profileName = $profile ?? self::DEFAULT_PROFILE;
        $entries = self::readProfile($path, $profileName);
        if ($entries === null) {
            return null;
        }

        $profileKey = $entries['api_key'] ?? null;
        if (is_string($profileKey) && $profileKey !== '') {
            return $profileKey;
        }

        return null;
    }

    /**
     * Resolve the base URL. Explicit `baseUrl` wins; otherwise an explicit
     * `environment` enum; otherwise the `GISL_BASE_URL` /
     * `GISL_ENVIRONMENT` env vars; otherwise the prod default. Never
     * throws — the chain always resolves to a usable URL.
     *
     * The "unknown explicit environment" fail-closed case from TS
     * (codex r2 medium 23a17c1dbf75) is structurally enforced here by
     * the {@see Environment} enum's type system: callers cannot pass an
     * unknown explicit value. The env-var path still accepts arbitrary
     * strings and silently falls through to the default on unknown
     * names — matching the TS env-var lenience.
     */
    public static function resolveEndpoint(
        ?string $baseUrl = null,
        ?Environment $environment = null,
    ): string {
        if ($baseUrl !== null && $baseUrl !== '') {
            return $baseUrl;
        }

        if ($environment !== null) {
            return self::ENVIRONMENT_ENDPOINTS[$environment->value];
        }

        $envBaseUrl = self::readEnv(self::GISL_BASE_URL_ENV);
        if ($envBaseUrl !== null) {
            return $envBaseUrl;
        }

        $envEnvironment = self::readEnv(self::GISL_ENVIRONMENT_ENV);
        if ($envEnvironment !== null && isset(self::ENVIRONMENT_ENDPOINTS[$envEnvironment])) {
            return self::ENVIRONMENT_ENDPOINTS[$envEnvironment];
        }

        return self::DEFAULT_ENDPOINT;
    }

    /**
     * Read a process-environment variable, treating empty strings as
     * "not set". Uses `getenv()` exclusively — `$_ENV` is only populated
     * when `variables_order` includes `E`, which is NOT the default
     * across all SAPIs (notably some FPM pools), so falling back to it
     * would create resolver-behaviour divergence between hosting modes.
     * Tests use `putenv()` which writes through to `getenv()` reliably.
     */
    private static function readEnv(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    }

    private static function defaultProfilePath(): ?string
    {
        $home = self::readEnv('HOME') ?? self::readEnv('USERPROFILE');
        if ($home === null) {
            return null;
        }
        // Forward slash join — works on every platform PHP supports.
        return rtrim($home, '/\\') . '/.gisl/credentials';
    }

    /**
     * Read a single profile section from an INI-format credentials file.
     *
     * Returns `null` when the file does not exist (no credentials from
     * this source — not an error). Throws {@see GislConfigError} when the
     * file exists but is malformed or the requested profile is absent.
     *
     * Implementation uses PHP's builtin `parse_ini_string` with
     * `INI_SCANNER_RAW` — research (php.watch) confirmed RAW mode
     * suppresses `${ENV_VAR}` interpolation, constant substitution, and
     * the "Norway problem" yes/no/true/false bool coercion that would
     * otherwise corrupt credential values. RAW also disables escape
     * sequences, which is acceptable for our credentials (alphanumeric
     * API keys, no embedded special chars expected).
     *
     * @return array<string, string>|null
     */
    private static function readProfile(string $path, string $profileName): ?array
    {
        // Pre-check existence so we can distinguish "no file" (return
        // null) from "file unreadable" (throw). is_file follows symlinks.
        if (!is_file($path)) {
            return null;
        }

        // Suppress E_WARNING from a permission-denied read — we surface
        // it as a typed GislConfigError instead. The error suppression
        // is local to this single call.
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new GislConfigError(
                "Failed to read shared credentials file at {$path} "
                . '(permission denied or filesystem error)',
            );
        }

        // INI_SCANNER_RAW + process_sections=true. RAW disables all
        // interpolation; process_sections gives us a [profile]-keyed
        // outer array. parse_ini_string emits an E_WARNING (e.g.
        // "syntax error, unexpected $end of file, expecting ']' in
        // Unknown on line 1") on malformed input — we install a
        // scoped error handler to capture the line-number fragment
        // for diagnostics, then re-raise as a typed GislConfigError.
        // Credential VALUES never enter the message; PHP's warning
        // text describes the syntax token, never the file body.
        $warningLine = null;
        set_error_handler(static function (
            int $_errno,
            string $errstr,
        ) use (&$warningLine): bool {
            if (preg_match('/on line (\d+)/', $errstr, $match) === 1) {
                $warningLine = (int) $match[1];
            }
            return true;
        });
        try {
            $parsed = parse_ini_string($raw, true, INI_SCANNER_RAW);
        } finally {
            restore_error_handler();
        }
        if ($parsed === false) {
            $where = $warningLine !== null
                ? "at line {$warningLine} of {$path}"
                : "at {$path}";
            throw new GislConfigError(
                "Malformed INI in shared credentials file {$where} "
                . "while looking up profile '{$profileName}'",
            );
        }

        if (!isset($parsed[$profileName])) {
            throw new GislConfigError(
                "Profile '{$profileName}' not found in shared credentials file at {$path}",
            );
        }

        $section = $parsed[$profileName];
        if (!is_array($section)) {
            // PHP coerces top-level scalar assignments at the file
            // root (outside any [section]) into a string keyed by
            // option name. If the caller's profile name happened to
            // collide with a root-level key, $parsed[$profileName] is a
            // scalar — we treat that as malformed.
            throw new GislConfigError(
                "Profile '{$profileName}' in shared credentials file at {$path} "
                . 'is not a valid section',
            );
        }

        /** @var array<string, string> $section */
        return $section;
    }
}
