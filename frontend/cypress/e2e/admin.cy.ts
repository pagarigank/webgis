describe('Admin Flows', () => {
  beforeEach(() => {
    // Intercept successful login
    cy.intercept('POST', '/api/v1/auth/login', {
      statusCode: 200,
      body: { success: true, data: { token: 'mock-token' } }
    }).as('loginRequest');

    // Intercept me endpoint with admin permissions
    cy.intercept('GET', '/api/v1/me', {
      statusCode: 200,
      body: {
        success: true,
        data: {
          user: { id: 1, username: 'admin', full_name: 'Admin' },
          roles: ['SYS_ADMIN'],
          permissions: ['user.manage', 'role.manage', 'scope.manage', 'system.config'],
          layer_capabilities: {},
          scopes: [],
          scope_version: 1
        }
      }
    }).as('meRequest');

    // Intercept users list
    cy.intercept('GET', '/api/v1/users', {
      statusCode: 200,
      body: {
        success: true,
        data: [
          { id: 1, username: 'admin', full_name: 'System Admin', status: 'ACTIVE' },
          { id: 2, username: 'j.doe', full_name: 'John Doe', status: 'ACTIVE' }
        ]
      }
    }).as('usersList');
  });

  it('can navigate to admin panel and view users', () => {
    // Navigate to login
    cy.visit('/login');
    cy.get('input[type="text"]').type('admin');
    cy.get('input[type="password"]').type('password123');
    cy.get('button[type="submit"]').click();

    cy.wait('@loginRequest');
    cy.wait('@meRequest');

    // Click Admin link in navbar
    cy.get('nav').contains('Admin').click();
    cy.url().should('include', '/admin');

    cy.wait('@usersList');

    // Ensure users are displayed
    cy.contains('Administration').should('be.visible');
    cy.contains('John Doe').should('be.visible');
    cy.contains('System Admin').should('be.visible');
  });
});
