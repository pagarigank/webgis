import { useContext } from 'react';
import { AuthContext } from './auth-context';
import type { AuthContextValue } from './auth-context';

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (ctx === null) {
    throw new Error('useAuth must be used inside <AuthProvider>.');
  }
  return ctx;
}