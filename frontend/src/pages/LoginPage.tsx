import { useState } from 'react';
import type { FormEvent } from 'react';
import { useLocation, useNavigate, Navigate } from 'react-router-dom';
import { useAuth } from '../auth/useAuth';
import { toApiError } from '../auth/apiErrors';

type LoginStep = 'credentials' | 'mfa';

const ERROR_MESSAGES: Record<string, string> = {
  AUTH_INVALID: 'Invalid username or password.',
  RATE_LIMITED: 'Too many attempts. Please wait a moment and try again.',
  VALIDATION_FAILED: 'Please check the details and try again.',
};

/* SVG icon helpers */
const UserIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
    <circle cx="12" cy="7" r="4"/>
  </svg>
);

const PasswordIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
  </svg>
);

const ShieldIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
  </svg>
);

const AlertIcon = () => (
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={{ flexShrink: 0, marginTop: 1 }}>
    <circle cx="12" cy="12" r="10"/>
    <line x1="12" y1="8" x2="12" y2="12"/>
    <line x1="12" y1="16" x2="12.01" y2="16"/>
  </svg>
);

const MapPinIcon = () => (
  <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
  </svg>
);

const LoginPage: React.FC = () => {
  const { login, verifyMfa, status } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [step, setStep] = useState<LoginStep>('credentials');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [mfaToken, setMfaToken] = useState<string | null>(null);
  const [mfaEnrolled, setMfaEnrolled] = useState(true);
  const [mfaCode, setMfaCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (status === 'authenticated') {
    return <Navigate to="/" replace />;
  }

  const from =
    (location.state as { from?: { pathname: string } } | null)?.from?.pathname ?? '/';

  const handleSubmit = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(username, password);
      navigate(from, { replace: true });
    } catch (err) {
      const apiError = toApiError(err);
      if (apiError.code === 'MFA_REQUIRED') {
        const token = apiError.details.mfa_token;
        if (typeof token === 'string') {
          setMfaToken(token);
          setMfaEnrolled(apiError.details.enrolled !== false);
          setStep('mfa');
        } else {
          setError('Two-factor verification is required, but the server did not start a session.');
        }
      } else {
        setError(ERROR_MESSAGES[apiError.code] ?? apiError.message);
      }
    } finally {
      setBusy(false);
    }
  };

  const handleMfaSubmit = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    if (mfaToken === null) return;
    setBusy(true);
    setError(null);
    try {
      await verifyMfa(mfaToken, mfaCode);
      navigate(from, { replace: true });
    } catch (err) {
      const apiError = toApiError(err);
      setError(ERROR_MESSAGES[apiError.code] ?? apiError.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="auth-layout">
      <div className="auth-card">
        {/* Logo / brand */}
        <div className="auth-logo-wrap">
          <div className="auth-logo-icon">
            <MapPinIcon />
          </div>
          <div>
            <h1 className="auth-title">WebGIS</h1>
            <p className="auth-subtitle">Philippine Parcel &amp; GIS Management</p>
          </div>
        </div>

        <form onSubmit={step === 'mfa' ? handleMfaSubmit : handleSubmit} noValidate>
          {step === 'credentials' && (
            <>
              <div className="auth-field">
                <label className="auth-label" htmlFor="login-username">Username</label>
                <div className="auth-input-icon-wrap">
                  <span className="auth-input-icon"><UserIcon /></span>
                  <input
                    id="login-username"
                    className="auth-input"
                    type="text"
                    autoComplete="username"
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    placeholder="Enter your username"
                    required
                    disabled={busy}
                    autoFocus
                  />
                </div>
              </div>

              <div className="auth-field">
                <label className="auth-label" htmlFor="login-password">Password</label>
                <div className="auth-input-icon-wrap">
                  <span className="auth-input-icon"><PasswordIcon /></span>
                  <input
                    id="login-password"
                    className="auth-input"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="Enter your password"
                    required
                    disabled={busy}
                  />
                </div>
              </div>
            </>
          )}

          {step === 'mfa' && (
            <>
              <div className="auth-step-label">
                <ShieldIcon /> Two-factor verification
              </div>

              {!mfaEnrolled && (
                <div className="auth-info">
                  Two-factor authentication is not enrolled yet. Complete device
                  enrollment first, then enter the verification code.
                </div>
              )}

              <div className="auth-field">
                <label className="auth-label" htmlFor="login-mfa-code">
                  6-digit verification code
                </label>
                <input
                  id="login-mfa-code"
                  className="auth-input"
                  style={{ textAlign: 'center', fontSize: '1.5rem', letterSpacing: '0.25em', fontFamily: 'var(--font-mono)' }}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  value={mfaCode}
                  onChange={(e) => setMfaCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  placeholder="000000"
                  required
                  pattern="[0-9]{6}"
                  maxLength={6}
                  disabled={busy}
                  autoFocus
                />
              </div>
            </>
          )}

          {error && (
            <div className="auth-error" role="alert">
              <AlertIcon />
              <span>{error}</span>
            </div>
          )}

          <button type="submit" className="auth-submit-btn" disabled={busy} id="login-submit">
            {busy ? (
              <><span className="spinner" aria-hidden="true" />Working…</>
            ) : step === 'mfa' ? (
              'Verify code'
            ) : (
              'Sign in'
            )}
          </button>

          {step === 'mfa' && (
            <button
              type="button"
              style={{ width: '100%', marginTop: '0.625rem', background: 'transparent', border: 'none', color: 'rgba(255,255,255,0.45)', fontSize: '0.8125rem', cursor: 'pointer', padding: '0.375rem' }}
              onClick={() => { setStep('credentials'); setError(null); setMfaCode(''); }}
            >
              ← Back to sign in
            </button>
          )}
        </form>

        <p style={{ textAlign: 'center', fontSize: '0.7rem', color: 'rgba(255,255,255,0.25)', marginTop: '1.5rem', marginBottom: 0, lineHeight: 1.5 }}>
          For authorised personnel only. All access is logged and audited.
        </p>
      </div>
    </div>
  );
};

export default LoginPage;