import { createContext } from 'react';
import type { AuthStatus, MePayload } from './types';

export interface AuthContextValue {
  status: AuthStatus;
  me: MePayload | null;
  user: MePayload['user'] | null;
  login: (username: string, password: string) => Promise<MePayload>;
  verifyMfa: (mfaToken: string, code: string) => Promise<MePayload>;
  changePassword: (currentPassword: string, newPassword: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshMe: () => Promise<MePayload>;
}

export const AuthContext = createContext<AuthContextValue | null>(null);