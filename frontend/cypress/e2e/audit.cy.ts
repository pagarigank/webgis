describe('Audit Flows', () => {
  beforeEach(() => {
    // Intercept successful login
    cy.intercept('POST', '/api/v1/auth/login', {
      statusCode: 200,
      body: { success: true, data: { token: 'mock-token' } }
    }).as('loginRequest');

    // Intercept me endpoint with audit permissions
    cy.intercept('GET', '/api/v1/me', {
      statusCode: 200,
      body: {
        success: true,
        data: {
          user: { id: 1, username: 'admin', full_name: 'Admin' },
          roles: ['SYS_ADMIN'],
          permissions: ['audit.view'],
          layer_capabilities: {},
          scopes: [],
          scope_version: 1
        }
      }
    }).as('meRequest');

    // Intercept audit logs
    cy.intercept('GET', '/api/v1/audit-logs*', {
      statusCode: 200,
      body: {
        success: true,
        data: [
          {
            id: 1,
            occurred_at: '2026-09-20T08:00:00Z',
            user_id: 1,
            username_snapshot: 'admin',
            action: 'CREATE',
            entity_type: 'USER',
            entity_id: '42',
            changed_fields: ['email'],
            ip: '192.168.1.1'
          }
        ]
      }
    }).as('auditLogs');
  });

  it('can navigate to audit logs and view them', () => {
    // Navigate to login
    cy.visit('/login');
    cy.get('input[type="text"]').type('admin');
    cy.get('input[type="password"]').type('password123');
    cy.get('button[type="submit"]').click();

    cy.wait('@loginRequest');
    cy.wait('@meRequest');

    // Click Audit link in navbar
    cy.get('nav').contains('Audit Logs').click();
    cy.url().should('include', '/audit');

    cy.wait('@auditLogs');

    // Ensure audit logs are displayed
    cy.contains('Audit Logs').should('be.visible');
    cy.contains('USER #42').should('be.visible');
    cy.contains('CREATE').should('be.visible');
  });
});
