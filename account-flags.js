/*
 * v1.9.28 public presentation flags ONLY. No passwords, tokens or API keys.
 * Do not flip these until the release gates and real browser tests are signed off.
 * The backend independently enforces its own password_reset_enabled flag.
 */
window.NavAccountFlags = Object.freeze({
  loginEnabled: false,
  passwordRecoveryEnabled: false
});
