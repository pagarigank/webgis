import { useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/useAuth';
import { toApiError } from '../auth/apiErrors';

/**
 * Forced / self-service password change (`PUT /me/password`). The backend
 * revokes the refresh family, so a successful change ends the session and we
 * return to the login screen. Violation details surface under
 * `details.field_errors.new_password` per the error envelope.
 */
const ChangePasswordPage: React.FC = () => {
  const { user, changePassword } = useAuth();
  const navigate = useNavigate();

  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [violations, setViolations] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);

  const handleSubmit = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setError(null);
    setViolations([]);

    if (next !== confirm) {
      setError('The new passwords do not match.');
      return;
    }
    if (next === current) {
      setError('The new password must be different from the current one.');
      return;
    }

    setBusy(true);
    try {
      await changePassword(current, next);
      navigate('/login', { replace: true });
    } catch (err) {
      const apiError = toApiError(err);
      if (apiError.code === 'AUTH_INVALID') {
        setError(apiError.message);
      } else {
        const fieldErrors = apiError.details?.field_errors as { new_password?: string[] } | undefined;
        setViolations(fieldErrors?.new_password ?? [apiError.message]);
      }
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
        onSubmit={handleSubmit}
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
        <h1 style={{ margin: 0, fontSize: '1.25rem' }}>Change password</h1>
        <p style={{ margin: 0, fontSize: '0.85rem', color: '#555' }}>
          {user?.must_change_password
            ? 'You must set a new password before continuing.'
            : `Signed in as ${user?.full_name ?? user?.username ?? ''}.`}
        </p>

        <input
          aria-label="Current password"
          type="password"
          autoComplete="current-password"
          value={current}
          onChange={(e) => setCurrent(e.target.value)}
          placeholder="Current password"
          required
          style={fieldStyle}
        />
        <input
          aria-label="New password"
          type="password"
          autoComplete="new-password"
          value={next}
          onChange={(e) => setNext(e.target.value)}
          placeholder="New password"
          required
          style={fieldStyle}
        />
        <input
          aria-label="Confirm new password"
          type="password"
          autoComplete="new-password"
          value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
          placeholder="Confirm new password"
          required
          style={fieldStyle}
        />

        {error && <p style={{ margin: 0, color: '#c62828', fontSize: '0.85rem' }}>{error}</p>}
        {violations.length > 0 && (
          <ul style={{ margin: 0, paddingLeft: '1.25rem', color: '#c62828', fontSize: '0.85rem' }}>
            {violations.map((message) => (
              <li key={message}>{message}</li>
            ))}
          </ul>
        )}

        <button type="submit" disabled={busy} style={buttonStyle}>
          {busy ? 'Working…' : 'Update password'}
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

export default ChangePasswordPage;