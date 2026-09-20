import { useCallback, useEffect, useMemo } from 'react';
import type { ReactNode } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiClient from '../lib/apiClient';
import { hasRefreshCookie, tokenStore } from './tokenStore';
import { AuthContext } from './auth-context';
import type { AuthContextValue } from './auth-context';
import type { AuthStatus, MePayload } from './types';

/**
 * Session state provider using TanStack Query.
 *
 * The access token lives only in `tokenStore` (memory). Login, MFA verify,
 * refresh and logout are orchestrated here.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();

  const meQuery = useQuery<MePayload>({
    queryKey: ['me'],
    queryFn: async () => {
      if (!hasRefreshCookie()) {
        throw new Error('No refresh cookie');
      }
      return await apiClient.get('/me');
    },
    retry: false, // Don't retry auth fetches
    staleTime: 5 * 60 * 1000, // Consider fresh for 5 minutes
  });

  // Derived auth status from TanStack Query
  const status: AuthStatus = useMemo(() => {
    if (meQuery.isPending) return 'loading';
    if (meQuery.isSuccess && meQuery.data) return 'authenticated';
    return 'unauthenticated';
  }, [meQuery.isPending, meQuery.isSuccess, meQuery.data]);

  // Eviction listener for unauthorized events from apiClient interceptor
  useEffect(() => {
    const handleUnauthorized = () => {
      queryClient.removeQueries({ queryKey: ['me'] });
      tokenStore.clear();
    };

    window.addEventListener('auth:unauthorized', handleUnauthorized);
    return () => window.removeEventListener('auth:unauthorized', handleUnauthorized);
  }, [queryClient]);

  const loginMutation = useMutation({
    mutationFn: async ({ username, password }: any) => {
      await apiClient.post('/auth/login', { username, password });
      return await apiClient.get('/me');
    },
    onSuccess: (data) => {
      queryClient.setQueryData(['me'], data);
    },
  });

  const verifyMfaMutation = useMutation({
    mutationFn: async ({ mfaToken, code }: any) => {
      await apiClient.post('/auth/mfa/verify', { mfa_token: mfaToken, code });
      return await apiClient.get('/me');
    },
    onSuccess: (data) => {
      queryClient.setQueryData(['me'], data);
    },
  });

  const changePasswordMutation = useMutation({
    mutationFn: async ({ currentPassword, newPassword }: any) => {
      await apiClient.put('/me/password', {
        current_password: currentPassword,
        new_password: newPassword,
      });
      return await apiClient.get('/me');
    },
    onSuccess: (data) => {
      queryClient.setQueryData(['me'], data);
    },
  });

  const logoutMutation = useMutation({
    mutationFn: async () => {
      try {
        await apiClient.post('/auth/logout');
      } catch {
        // Ignore server errors on logout
      }
    },
    onSettled: () => {
      tokenStore.clear();
      queryClient.removeQueries({ queryKey: ['me'] });
    },
  });

  const value = useMemo<AuthContextValue>(
    () => ({
      status,
      me: meQuery.data ?? null,
      user: meQuery.data?.user ?? null,
      login: async (username, password) => loginMutation.mutateAsync({ username, password }) as unknown as MePayload,
      verifyMfa: async (mfaToken, code) => verifyMfaMutation.mutateAsync({ mfaToken, code }) as unknown as MePayload,
      changePassword: async (currentPassword, newPassword) => changePasswordMutation.mutateAsync({ currentPassword, newPassword }) as unknown as void,
      logout: async () => logoutMutation.mutateAsync(),
      refreshMe: async () => {
        const result = await meQuery.refetch();
        if (result.error) throw result.error;
        return result.data as MePayload;
      },
    }),
    [status, meQuery, loginMutation, verifyMfaMutation, changePasswordMutation, logoutMutation]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}