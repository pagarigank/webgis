/// <reference types="cypress" />

describe('Auth Flow', () => {
  beforeEach(() => {
    cy.clearAllCookies();
    cy.clearAllSessionStorage();
    cy.clearAllLocalStorage();
  });

  it('evicts to /login when missing refresh cookie', () => {
    cy.visit('/');
    cy.url().should('include', '/login');
  });

  it('successfully logs in and navigates to home', () => {
    cy.intercept('POST', '/api/v1/auth/login', {
      statusCode: 200,
      body: { success: true, data: { access_token: 'fake-jwt' } },
      headers: {
        'x-csrf-token': 'fake-csrf',
        'set-cookie': 'refresh_token=fake-cookie; Path=/; HttpOnly',
      },
    }).as('loginRequest');

    cy.intercept('GET', '/api/v1/me', {
      statusCode: 200,
      body: {
        success: true,
        data: {
          user: { id: 1, username: 'testuser', full_name: 'Test User' },
          roles: ['SYS_ADMIN'],
          permissions: [],
          layer_capabilities: {},
          scopes: [],
          scope_version: 1,
        },
      },
    }).as('meRequest');

    cy.visit('/login');

    cy.get('input[type="text"]').type('testuser');
    cy.get('input[type="password"]').type('password');
    cy.get('button[type="submit"]').click();

    cy.wait('@loginRequest');
    cy.wait('@meRequest');

    cy.url().should('eq', Cypress.config().baseUrl + '/');
    cy.contains('Signed in as Test User');
  });

  it('silently refreshes on 401', () => {
    cy.setCookie('refresh_token', 'valid-refresh-cookie');

    let requestCount = 0;
    
    // First /me fails with 401, second /me succeeds
    cy.intercept('GET', '/api/v1/me', (req) => {
      requestCount++;
      if (requestCount === 1) {
        req.reply({ statusCode: 401, body: { success: false, error: { code: 'UNAUTHORIZED', message: 'Expired' } } });
      } else {
        req.reply({
          statusCode: 200,
          body: {
            success: true,
            data: { user: { id: 1, username: 'testuser', full_name: 'Test User' }, roles: [], permissions: [], layer_capabilities: {}, scopes: [], scope_version: 1 },
          },
        });
      }
    }).as('meRequest');

    cy.intercept('POST', '/api/v1/auth/refresh', {
      statusCode: 200,
      body: { success: true, data: { access_token: 'new-jwt' } },
      headers: { 'x-csrf-token': 'new-csrf' }
    }).as('refreshRequest');

    cy.visit('/');

    cy.wait('@meRequest'); // Fails 401
    cy.wait('@refreshRequest'); // Triggers refresh
    cy.wait('@meRequest'); // Retries and succeeds

    cy.url().should('eq', Cypress.config().baseUrl + '/');
    cy.contains('Signed in as Test User');
  });

  it('evicts to /login when silent refresh fails', () => {
    cy.setCookie('refresh_token', 'bad-refresh-cookie');

    cy.intercept('GET', '/api/v1/me', {
      statusCode: 401,
      body: { success: false, error: { code: 'UNAUTHORIZED', message: 'Expired' } }
    }).as('meRequest');

    cy.intercept('POST', '/api/v1/auth/refresh', {
      statusCode: 401,
      body: { success: false, error: { code: 'AUTH_INVALID', message: 'Invalid refresh token' } }
    }).as('refreshRequest');

    cy.visit('/status');

    cy.wait('@meRequest'); // Fails 401
    cy.wait('@refreshRequest'); // Triggers refresh, which ALSO fails 401

    // Should emit auth:unauthorized and evict instantly via AuthContext
    cy.url().should('include', '/login');
  });
});
