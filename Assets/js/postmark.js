/**
 * Postmark campaign action JavaScript functionality
 */

// Global function to load templates based on selected server
Mautic.postmarkLoadTemplates = function(serverSelectElement) {
    var serverToken = mQuery(serverSelectElement).val();
    var form = mQuery(serverSelectElement).closest('form');
    var templateSelect = form.find('.postmark-template-select');
    
    if (!templateSelect.length) {
        // If template select doesn't exist, try multiple selectors
        templateSelect = form.find('[name*="template_alias"]');
        if (!templateSelect.length) {
            templateSelect = form.find('select[id*="template_alias"]');
        }
        if (!templateSelect.length) {
            templateSelect = form.find('select').filter(function() {
                return mQuery(this).attr('name') && mQuery(this).attr('name').indexOf('template_alias') >= 0;
            });
        }
    }
    
    if (!templateSelect.length) {
        return;
    }
    
    if (!serverToken) {
        // Clear template options if no server selected
        templateSelect.empty().append('<option value="">Please select a server first</option>');
        
        // Trigger chosen update
        if (templateSelect.hasClass('chosen-select') || templateSelect.next('.chosen-container').length > 0) {
            templateSelect.trigger('chosen:updated');
        }
        return;
    }

    // Show loading state
    templateSelect.empty().append('<option value="">Loading templates...</option>');
    templateSelect.prop('disabled', true);

    // Fetch templates from server
    mQuery.ajax({
        url: mauticAjaxUrl + '?action=plugin:postmark:getTemplates',
        type: 'POST',
        data: {
            server_token: serverToken
        },
        dataType: 'json',
        success: function(response) {
            templateSelect.empty();
            templateSelect.prop('disabled', false);

            if (response.success && response.templates && Object.keys(response.templates).length > 0) {
                // Add default option
                templateSelect.append('<option value="">Select a template</option>');
                
                // Add template options - the response format is {"Template Name (alias)": "alias"}
                mQuery.each(response.templates, function(label, alias) {
                    templateSelect.append(mQuery('<option></option>')
                        .attr('value', alias)
                        .text(label)
                    );
                });
                
                // Trigger chosen update if the select is using Chosen plugin
                if (templateSelect.hasClass('chosen-select') || templateSelect.next('.chosen-container').length > 0) {
                    templateSelect.trigger('chosen:updated');
                }
            } else {
                var message = response.message || 'No templates found for this server';
                templateSelect.append('<option value="">' + message + '</option>');
                
                // Trigger chosen update for error message too
                if (templateSelect.hasClass('chosen-select') || templateSelect.next('.chosen-container').length > 0) {
                    templateSelect.trigger('chosen:updated');
                }
            }
        },
        error: function() {
            templateSelect.empty();
            templateSelect.prop('disabled', false);
            templateSelect.append('<option value="">Error loading templates</option>');
            
            // Trigger chosen update for error case
            if (templateSelect.hasClass('chosen-select') || templateSelect.next('.chosen-container').length > 0) {
                templateSelect.trigger('chosen:updated');
            }
        }
    });
};

// Function to load template variables and populate the template model fields
Mautic.postmarkLoadTemplateVariables = function(templateSelectElement) {
    var templateAlias = mQuery(templateSelectElement).val();
    var form = mQuery(templateSelectElement).closest('form');

    // Find the server select element to get the server token
    var serverSelect = form.find('.postmark-server-select');
    var serverToken = serverSelect.val();

    console.log('Postmark: Loading template variables for template:', templateAlias);

    if (!templateAlias || !serverToken) {
        console.log('Postmark: Missing template alias or server token');
        return;
    }

    // Fetch template variables from server
    mQuery.ajax({
        url: mauticAjaxUrl + '?action=plugin:postmark:getTemplateVariables',
        type: 'POST',
        data: {
            server_token: serverToken,
            template_alias: templateAlias
        },
        dataType: 'json',
        success: function(response) {
            console.log('Postmark: Template variables response:', response);
            if (response.success) {
                if (response.variables && response.variables.length > 0) {
                    console.log('Postmark: Found ' + response.variables.length + ' variables:', response.variables);
                    Mautic.postmarkPopulateTemplateModel(form, response.variables);
                } else {
                    console.log('Postmark: No variables found');
                    // Still clear existing fields even when no variables found
                    Mautic.postmarkPopulateTemplateModel(form, []);
                }
            } else {
                console.log('Postmark: Error in response:', response.message);
                // Clear fields on error too
                Mautic.postmarkPopulateTemplateModel(form, []);
            }
        },
        error: function(xhr, status, error) {
            console.error('Postmark: AJAX error:', status, error);
            // Error - clear existing fields
            Mautic.postmarkPopulateTemplateModel(form, []);
        }
    });
};

// Function to clear existing template model variables
Mautic.postmarkClearTemplateModel = function(templateModelContainer) {
    // Find the sortable list container
    var sortableContainer = templateModelContainer.find('.list-sortable');
    if (sortableContainer.length) {
        // Find all sortable items (div.sortable)
        var sortableItems = sortableContainer.find('.sortable');
        
        // Remove all sortable items
        sortableItems.remove();
        
        // Reset the item counter
        var itemCounter = templateModelContainer.find('.sortable-itemcount, input[name*="itemcount"]');
        if (itemCounter.length > 0) {
            itemCounter.val('0');
        }
    }
};

// Function to populate the template model with cascading dropdowns
Mautic.postmarkPopulateTemplateModel = function(form, variables) {
    console.log('Postmark: Populating template model with', variables.length, 'variables');

    // Find the list-sortable container directly
    var sortableList = form.find('.list-sortable[id*="template_model"]');
    console.log('Postmark: Found sortable list (method 1):', sortableList.length);

    if (!sortableList.length) {
        sortableList = form.find('#sortable-campaignevent_properties_template_model, #sortable-postmark_send_template_model');
        console.log('Postmark: Found sortable list (method 2):', sortableList.length);
    }

    if (!sortableList.length) {
        console.error('Postmark: Could not find sortable list container');
        return;
    }

    console.log('Postmark: Sortable list ID:', sortableList.attr('id'));

    // Find the form-group container that holds the sortable list
    var formGroup = sortableList.closest('.form-group');
    if (!formGroup.length) {
        formGroup = sortableList.parent();
    }

    console.log('Postmark: Form group found:', formGroup.length);

    // CLEAR EXISTING VARIABLES FIRST
    Mautic.postmarkClearTemplateModel(formGroup);

    // Hide the sortable list and its controls
    console.log('Postmark: Hiding sortable list and controls');
    sortableList.hide();
    formGroup.find('.btn-add-item').hide(); // Hide the add button

    // Create new custom interface for variable mapping
    if (variables && variables.length > 0) {
        // Determine the field name prefix from existing inputs
        var fieldNamePrefix = Mautic.postmarkGetFieldNamePrefix(sortableList);
        console.log('Postmark: Field name prefix:', fieldNamePrefix);
        Mautic.postmarkCreateVariableMappingInterface(formGroup, variables, fieldNamePrefix);
    } else {
        console.log('Postmark: No variables to display');
    }
};

// Helper function to get the field name prefix from existing sortable list
Mautic.postmarkGetFieldNamePrefix = function(sortableList) {
    // Try to find an existing input to determine the naming pattern
    var existingInput = sortableList.find('input[name*="template_model"]').first();
    if (existingInput.length) {
        var name = existingInput.attr('name');
        // Extract the prefix: campaignevent[properties][template_model] or postmark_send[template_model]
        var match = name.match(/^(.+\[template_model\])\[/);
        if (match) {
            return match[1];
        }
    }

    // Default fallback based on the sortable list ID
    var listId = sortableList.attr('id');
    if (listId && listId.indexOf('campaignevent') >= 0) {
        return 'campaignevent[properties][template_model]';
    }

    return 'postmark_send[template_model]';
};

// Create the new variable mapping interface with cascading dropdowns
Mautic.postmarkCreateVariableMappingInterface = function(templateModelContainer, variables, fieldNamePrefix) {
    // Create or clear our custom container
    var customContainer = templateModelContainer.find('.postmark-variable-mapping');
    if (!customContainer.length) {
        customContainer = mQuery('<div class="postmark-variable-mapping"></div>');
        templateModelContainer.append(customContainer);
    } else {
        customContainer.empty();
    }

    // Load modules first, then create the interface
    Mautic.postmarkLoadModules(function(modules) {
        if (!modules || Object.keys(modules).length === 0) {
            customContainer.html('<div class="alert alert-warning">No modules available for mapping</div>');
            return;
        }

        // Add some helpful text
        customContainer.append('<p class="help-block"><i class="fa fa-info-circle"></i> Map each Postmark template variable to a Mautic field</p>');

        // Create a mapping row for each variable
        variables.forEach(function(variable, index) {
            var row = Mautic.postmarkCreateMappingRow(variable, index, modules, fieldNamePrefix);
            customContainer.append(row);
        });
    });
};

// Load available modules via AJAX
Mautic.postmarkLoadModules = function(callback) {
    mQuery.ajax({
        url: mauticAjaxUrl + '?action=plugin:postmark:getModules',
        type: 'POST',
        dataType: 'json',
        success: function(response) {
            if (response.success && response.modules) {
                callback(response.modules);
            } else {
                callback({});
            }
        },
        error: function() {
            callback({});
        }
    });
};

// Create a single mapping row for a variable
Mautic.postmarkCreateMappingRow = function(variable, index, modules, fieldNamePrefix) {
    var row = mQuery('<div class="postmark-mapping-row"></div>');

    // Variable name (read-only display)
    var variableLabel = mQuery('<div class="form-group" style="margin-bottom: 10px;"></div>');
    variableLabel.append('<label class="control-label" style="font-weight: bold;">Template Variable</label>');
    variableLabel.append('<div class="input-group"><span class="input-group-addon"><i class="fa fa-code"></i></span><input type="text" class="form-control" value="{{' + variable + '}}" readonly style="background: #fff; cursor: not-allowed;"></div>');
    row.append(variableLabel);

    // Module dropdown
    var moduleGroup = mQuery('<div class="form-group" style="margin-bottom: 10px;"></div>');
    moduleGroup.append('<label class="control-label">Module</label>');

    var moduleSelect = mQuery('<select class="form-control postmark-module-select" data-variable="' + variable + '" data-index="' + index + '"></select>');
    moduleSelect.append('<option value="">-- Select Module --</option>');

    mQuery.each(modules, function(moduleKey, moduleName) {
        moduleSelect.append('<option value="' + moduleKey + '">' + moduleName + '</option>');
    });

    moduleGroup.append(moduleSelect);
    row.append(moduleGroup);

    // Field dropdown (initially empty)
    var fieldGroup = mQuery('<div class="form-group" style="margin-bottom: 0;"></div>');
    fieldGroup.append('<label class="control-label">Field</label>');

    var fieldSelect = mQuery('<select class="form-control postmark-field-select" data-variable="' + variable + '" data-index="' + index + '" disabled></select>');
    fieldSelect.append('<option value="">-- Select Module First --</option>');

    fieldGroup.append(fieldSelect);
    row.append(fieldGroup);

    // Add hidden inputs to store the mapping (for form submission)
    // Use the dynamic field name prefix
    var hiddenInputs = mQuery('<div style="display:none;"></div>');
    hiddenInputs.append('<input type="hidden" class="postmark-hidden-variable" name="' + fieldNamePrefix + '[list][' + index + '][label]" value="' + variable + '">');
    hiddenInputs.append('<input type="hidden" class="postmark-hidden-module" name="' + fieldNamePrefix + '[list][' + index + '][module]" value="">');
    hiddenInputs.append('<input type="hidden" class="postmark-hidden-field" name="' + fieldNamePrefix + '[list][' + index + '][value]" value="">');
    row.append(hiddenInputs);

    // Add event handler for module selection
    moduleSelect.on('change', function() {
        var selectedModule = mQuery(this).val();
        var relatedFieldSelect = row.find('.postmark-field-select');
        var hiddenModule = row.find('.postmark-hidden-module');
        var hiddenField = row.find('.postmark-hidden-field');

        // Update hidden module input
        hiddenModule.val(selectedModule);

        if (!selectedModule) {
            relatedFieldSelect.empty().append('<option value="">-- Select Module First --</option>').prop('disabled', true);
            hiddenField.val('');
            return;
        }

        // Load fields for selected module
        Mautic.postmarkLoadModuleFields(selectedModule, relatedFieldSelect, hiddenField);
    });

    // Add event handler for field selection
    fieldSelect.on('change', function() {
        var selectedField = mQuery(this).val();
        var hiddenField = row.find('.postmark-hidden-field');
        hiddenField.val(selectedField);
    });

    return row;
};

// Load fields for a selected module
Mautic.postmarkLoadModuleFields = function(module, fieldSelect, hiddenField) {
    console.log('Postmark JS: Loading fields for module:', module);

    // Show loading state
    fieldSelect.empty().append('<option value="">Loading fields...</option>').prop('disabled', true);
    hiddenField.val('');

    mQuery.ajax({
        url: mauticAjaxUrl + '?action=plugin:postmark:getModuleFields',
        type: 'POST',
        data: {
            module: module
        },
        dataType: 'json',
        success: function(response) {
            console.log('Postmark JS: Got response:', response);
            console.log('Postmark JS: response.success =', response.success);
            console.log('Postmark JS: response.fields =', response.fields);
            console.log('Postmark JS: Field count =', response.fields ? Object.keys(response.fields).length : 0);

            fieldSelect.empty().prop('disabled', false);

            if (response.success && response.fields && Object.keys(response.fields).length > 0) {
                console.log('Postmark JS: Adding ' + Object.keys(response.fields).length + ' fields to dropdown');
                fieldSelect.append('<option value="">-- Select Field --</option>');

                mQuery.each(response.fields, function(fieldAlias, fieldLabel) {
                    fieldSelect.append('<option value="' + fieldAlias + '">' + fieldLabel + '</option>');
                });
            } else {
                console.log('Postmark JS: No fields found, showing message');
                var message = response.message || 'No fields found for this module';
                fieldSelect.append('<option value="">' + message + '</option>');
            }
        },
        error: function(xhr, status, error) {
            console.error('Postmark JS: AJAX error:', status, error);
            fieldSelect.empty().prop('disabled', false);
            fieldSelect.append('<option value="">Error loading fields</option>');
        }
    });
};