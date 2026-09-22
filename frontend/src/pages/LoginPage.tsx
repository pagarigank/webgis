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
    if (mfaToken === null) {
      return;
    }
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
    <div
      style={{
        height: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontFamily: 'system-ui, sans-serif',
      }}
    >
      <form
        onSubmit={step === 'mfa' ? handleMfaSubmit : handleSubmit}
        style={{
          width: 320,
          padding: '2rem',
          border: '1px solid #ccc',
          borderRadius: 8,
          display: 'flex',
          flexDirection: 'column',
          gap: '0.75rem',
        }}
      >
        <h1 style={{ margin: 0, fontSize: '1.25rem' }}>
          {step === 'mfa' ? 'Two-factor verification' : 'Sign in'}
        </h1>

        {step === 'credentials' && (
          <>
            <input
              aria-label="Username"
              autoComplete="username"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="Username"
              required
              style={fieldStyle}
            />
            <input
              aria-label="Password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Password"
              required
              style={fieldStyle}
            />
          </>
        )}

        {step === 'mfa' && (
          <>
            {!mfaEnrolled && (
              <p style={{ margin: 0, fontSize: '0.85rem', color: '#555' }}>
                Two-factor authentication is not enrolled yet. Complete device
                enrollment first, then enter the verification code.
              </p>
            )}
            <input
              aria-label="Verification code"
              inputMode="numeric"
              autoComplete="one-time-code"
              value={mfaCode}
              onChange={(e) => setMfaCode(e.target.value)}
              placeholder="6-digit code"
              required
              pattern="[0-9]{6}"
              style={fieldStyle}
            />
          </>
        )}

        {error && <p style={{ margin: 0, color: '#c62828', fontSize: '0.85rem' }}>{error}</p>}

        <button type="submit" disabled={busy} style={buttonStyle}>
          {busy ? 'Working…' : step === 'mfa' ? 'Verify' : 'Sign in'}
        </button>
      </form>
    </div>
  );
};

const fieldStyle: React.CSSProperties = {
  padding: '0.5rem 0.75rem',
  border: '1px solid #ccc',
  borderRadius: 6,
  fontSize: '1rem',
};

const buttonStyle: React.CSSProperties = {
  padding: '0.6rem',
  border: 'none',
  borderRadius: 6,
  background: '#1a5fb4',
  color: '#fff',
  fontSize: '1rem',
  cursor: 'pointer',
};

export default LoginPage;