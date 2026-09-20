describe('Layer Designer Flow', () => {
  beforeEach(() => {
    cy.visit('/login');
    cy.get('input[name="username"]').type('admin');
    cy.get('input[name="password"]').type('admin123');
    cy.get('button[type="submit"]').click();
    cy.url().should('eq', Cypress.config().baseUrl + '/');
  });

  it('can create a layer with fields and a categorized style', () => {
    // 1. Navigate to Layers Manager
    cy.get('nav.app-nav').contains('Admin').click();
    cy.get('nav.tabs').contains('GIS Layers').click();
    
    // 2. Create new layer
    cy.contains('Create Layer').click();
    cy.url().should('include', '/admin/layers/new');

    // 3. Fill out metadata
    const layerCode = 'TEST_LAYER_' + Date.now();
    cy.get('input[name="code"]').type(layerCode);
    cy.get('input[name="name"]').type('E2E Test Layer');
    cy.get('input[name="group_path"]').clear().type('Tests');
    cy.get('select[name="geometry_type"]').select('POLYGON');
    cy.get('button[type="submit"]').contains('Save Layer Settings').click();

    // 4. Verify save success
    cy.url().should('not.include', '/new');

    // 5. Add five distinct fields
    const addField = (name: string, label: string, type: string) => {
        cy.contains('Add Field').click();
        cy.get('input[name="field_name"]').clear().type(name);
        cy.get('input[name="field_label"]').clear().type(label);
        cy.get('select[name="field_type"]').select(type);
        cy.get('button[type="submit"]').contains('Save Field').click();
        cy.contains(name).should('exist');
    };

    addField('f_text', 'Text Field', 'text');
    addField('f_int', 'Integer Field', 'integer');
    addField('f_bool', 'Boolean Field', 'boolean');
    addField('f_drop', 'Dropdown Field', 'dropdown');
    addField('f_date', 'Date Field', 'date');

    // 6. Create a style
    cy.contains('Create Style').click();
    cy.get('select').first().select('CATEGORIZED'); // style_type select
    cy.get('input').eq(1).type('f_drop'); // attribute_field input
    cy.contains('Add Rule').click();
    cy.get('input[placeholder="Category value"]').type('RESIDENTIAL');
    cy.get('button[type="submit"]').contains('Save Style').click();
    
    cy.contains('CATEGORIZED').should('exist');
    cy.contains('f_drop').should('exist');
  });
});
