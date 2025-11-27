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

    // CLEAR EXISTING VARIABLES FIRST to avoid input conflicts
    console.log('Postmark: Clearing existing template model');
    Mautic.postmarkClearTemplateModel(formGroup);

    // Also clear the sortable list items themselves
    sortableList.find('.sortable').remove();

    // Update the item counter to 0
    var itemCounter = formGroup.find('.sortable-itemcount, input[name*="itemcount"]');
    if (itemCounter.length > 0) {
        itemCounter.val('0');
    }

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

    // Update the existing sortable item counter without adding new named inputs
    var itemCountField = templateModelContainer.find('.sortable-itemcount');
    if (itemCountField.length) {
        itemCountField.val(variables.length);
    } else {
        customContainer.append('<input type="hidden" class="sortable-itemcount postmark-itemcount" value="' + variables.length + '">');
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

    // Module dropdown (with Static Value option)
    var moduleGroup = mQuery('<div class="form-group" style="margin-bottom: 10px;"></div>');
    moduleGroup.append('<label class="control-label">Value Type</label>');

    var moduleSelect = mQuery('<select class="form-control postmark-module-select" data-variable="' + variable + '" data-index="' + index + '"></select>');
    moduleSelect.append('<option value="">-- Select Type --</option>');
    moduleSelect.append('<option value="static">📝 Static Value (Manual Input)</option>');

    mQuery.each(modules, function(moduleKey, moduleName) {
        moduleSelect.append('<option value="' + moduleKey + '">🔗 ' + moduleName + ' Field</option>');
    });

    moduleGroup.append(moduleSelect);
    row.append(moduleGroup);

    // Field dropdown (initially empty) - for module fields
    var fieldGroup = mQuery('<div class="form-group postmark-field-group" style="margin-bottom: 0;"></div>');
    fieldGroup.append('<label class="control-label">Field</label>');

    var fieldSelect = mQuery('<select class="form-control postmark-field-select" data-variable="' + variable + '" data-index="' + index + '" disabled></select>');
    fieldSelect.append('<option value="">-- Select Type First --</option>');

    fieldGroup.append(fieldSelect);
    row.append(fieldGroup);

    // Static value input (initially hidden) - for manual text input
    var staticGroup = mQuery('<div class="form-group postmark-static-group" style="margin-bottom: 0; display: none;"></div>');
    staticGroup.append('<label class="control-label">Static Value</label>');

    var staticInput = mQuery('<input type="text" class="form-control postmark-static-input" data-variable="' + variable + '" data-index="' + index + '" placeholder="Enter static value...">');
    staticGroup.append(staticInput);

    row.append(staticGroup);

    // Add hidden inputs to store the mapping (for form submission)
    // Use the dynamic field name prefix
    var hiddenInputs = mQuery('<div style="display:none;"></div>');
    hiddenInputs.append('<input type="hidden" class="postmark-hidden-variable" name="' + fieldNamePrefix + '[list][' + index + '][label]" value="' + variable + '">');
    hiddenInputs.append('<input type="hidden" class="postmark-hidden-field" name="' + fieldNamePrefix + '[list][' + index + '][value]" value="">');
    row.append(hiddenInputs);

    // Add event handler for module/type selection
    moduleSelect.on('change', function() {
        var selectedModule = mQuery(this).val();
        var relatedFieldSelect = row.find('.postmark-field-select');
        var relatedFieldGroup = row.find('.postmark-field-group');
        var relatedStaticInput = row.find('.postmark-static-input');
        var relatedStaticGroup = row.find('.postmark-static-group');
        var hiddenField = row.find('.postmark-hidden-field');

        console.log('Postmark: Module selected:', selectedModule);
        console.log('Postmark: Static group found:', relatedStaticGroup.length);
        console.log('Postmark: Field group found:', relatedFieldGroup.length);

        if (!selectedModule) {
            // Nothing selected - show field dropdown disabled
            relatedFieldGroup.css('display', 'block');
            relatedStaticGroup.css('display', 'none');
            relatedFieldSelect.empty().append('<option value="">-- Select Type First --</option>').prop('disabled', true);
            relatedStaticInput.val('');
            hiddenField.val('');
            return;
        }

        if (selectedModule === 'static') {
            // Static value selected - show text input, hide field dropdown
            console.log('Postmark: Static value selected, showing static input');
            relatedFieldGroup.css('display', 'none');
            relatedStaticGroup.css('display', 'block');
            relatedFieldSelect.empty().prop('disabled', true);
            // Don't overwrite if static input already has a value
            if (!relatedStaticInput.val()) {
                hiddenField.val('static:');
            } else {
                hiddenField.val('static:' + relatedStaticInput.val());
            }
            console.log('Postmark: Static group display now:', relatedStaticGroup.css('display'));
            return;
        }

        // Module selected - show field dropdown, hide static input
        relatedFieldGroup.css('display', 'block');
        relatedStaticGroup.css('display', 'none');
        relatedStaticInput.val('');

        // Load fields for selected module
        Mautic.postmarkLoadModuleFields(selectedModule, relatedFieldSelect, hiddenField);
    });

    // Add event handler for field selection
    fieldSelect.on('change', function() {
        var selectedField = mQuery(this).val();
        var selectedModule = row.find('.postmark-module-select').val();
        var hiddenField = row.find('.postmark-hidden-field');

        // Store in format "module:field" so we can restore it later
        if (selectedField && selectedModule && selectedModule !== 'static') {
            hiddenField.val(selectedModule + ':' + selectedField);
        } else {
            hiddenField.val('');
        }
    });

    // Add event handler for static input
    staticInput.on('input', function() {
        var staticValue = mQuery(this).val();
        var hiddenField = row.find('.postmark-hidden-field');

        // Store in format "static:value"
        hiddenField.val('static:' + staticValue);
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

// Initialize the custom interface on page load if there's existing data
Mautic.postmarkInitializeOnLoad = function() {
    console.log('Postmark: Checking for existing template model data on page load');

    // Look for the sortable list in the Postmark form
    var sortableList = mQuery('.list-sortable[id*="template_model"]');

    if (!sortableList.length) {
        console.log('Postmark: No sortable list found');
        return;
    }

    // Check if already initialized (has our custom container)
    var formGroup = sortableList.closest('.form-group');
    if (!formGroup.length) {
        formGroup = sortableList.parent();
    }

    if (formGroup.find('.postmark-variable-mapping').length) {
        console.log('Postmark: Already initialized, skipping');
        return;
    }

    // Check if sortable list is already hidden
    if (sortableList.is(':hidden')) {
        console.log('Postmark: Sortable list already hidden, skipping');
        return;
    }

    // Check if it has any existing items (saved data)
    var existingItems = sortableList.find('.sortable');

    if (!existingItems.length) {
        console.log('Postmark: No existing items found');
        return;
    }

    console.log('Postmark: Found ' + existingItems.length + ' existing items, converting to custom interface');

    // Extract the saved variables and their mappings
    var savedMappings = [];
    existingItems.each(function() {
        var item = mQuery(this);
        var label = item.find('.sortable-label').val(); // Variable name
        var value = item.find('.sortable-value').val(); // Module field (or module:field format)

        if (label) {
            savedMappings.push({
                variable: label,
                value: value
            });
        }
    });

    console.log('Postmark: Extracted saved mappings:', savedMappings);

    // Find the form and sortable list container
    var form = sortableList.closest('form');
    var formGroup = sortableList.closest('.form-group');
    if (!formGroup.length) {
        formGroup = sortableList.parent();
    }

    // Get field name prefix
    var fieldNamePrefix = Mautic.postmarkGetFieldNamePrefix(sortableList);
    console.log('Postmark: Field name prefix:', fieldNamePrefix);

    // IMPORTANT: Clear the sortable list items to avoid input conflicts
    console.log('Postmark: Clearing old sortable list items');
    sortableList.find('.sortable').remove();

    // Update the item counter to 0
    var itemCounter = formGroup.find('.sortable-itemcount, input[name*="itemcount"]');
    if (itemCounter.length > 0) {
        itemCounter.val('0');
    }

    // Hide the sortable list and add button
    sortableList.hide();
    formGroup.find('.btn-add-item').hide();

    // Create custom interface with saved data
    Mautic.postmarkCreateVariableMappingInterfaceWithData(formGroup, savedMappings, fieldNamePrefix);

    // Hook into form submission to ensure data is properly formatted
    var campaignForm = formGroup.closest('form');
    if (campaignForm.length) {
        console.log('Postmark: Setting up form submit handler');
        campaignForm.off('submit.postmark').on('submit.postmark', function(e) {
            console.log('Postmark: Form submitting, checking hidden inputs');

            // Set flag to prevent reinitialization during submission
            window.postmarkFormSubmitting = true;
            console.log('Postmark: Set formSubmitting flag to true');

            // Log all our hidden inputs to see what's being submitted
            var hiddenInputs = formGroup.find('.postmark-hidden-variable, .postmark-hidden-field');
            console.log('Postmark: Found ' + hiddenInputs.length + ' hidden inputs');

            hiddenInputs.each(function() {
                var input = mQuery(this);
                console.log('  - ' + input.attr('name') + ' = ' + input.val());
            });

            // Check if itemcount is set
            var itemCount = formGroup.find('input[name*="itemcount"]');
            console.log('Postmark: Itemcount value:', itemCount.val());

            // Reset flag after a delay to allow modal to close
            setTimeout(function() {
                window.postmarkFormSubmitting = false;
                console.log('Postmark: Reset formSubmitting flag to false');
            }, 3000);

            // Let the form submit normally
            return true;
        });
    }
};

// Create variable mapping interface with pre-populated data
Mautic.postmarkCreateVariableMappingInterfaceWithData = function(templateModelContainer, savedMappings, fieldNamePrefix) {
    // Create or clear our custom container
    var customContainer = templateModelContainer.find('.postmark-variable-mapping');
    if (!customContainer.length) {
        customContainer = mQuery('<div class="postmark-variable-mapping"></div>');
        templateModelContainer.append(customContainer);
    } else {
        customContainer.empty();
    }

    // Update the existing sortable item counter without adding new named inputs
    var itemCountField = templateModelContainer.find('.sortable-itemcount');
    if (itemCountField.length) {
        itemCountField.val(savedMappings.length);
    } else {
        customContainer.append('<input type="hidden" class="sortable-itemcount postmark-itemcount" value="' + savedMappings.length + '">');
    }

    // Load modules first, then create the interface with saved data
    Mautic.postmarkLoadModules(function(modules) {
        if (!modules || Object.keys(modules).length === 0) {
            customContainer.html('<div class="alert alert-warning">No modules available for mapping</div>');
            return;
        }

        // Add helpful text
        customContainer.append('<p class="help-block"><i class="fa fa-info-circle"></i> Map each Postmark template variable to a Mautic field</p>');

        // Create a mapping row for each saved variable
        savedMappings.forEach(function(mapping, index) {
            console.log('Postmark: Creating row for variable:', mapping.variable, 'with value:', mapping.value);

            // Parse the saved value to extract module and field
            // Format can be "fieldName" or we need to detect the module
            var savedModule = '';
            var savedField = mapping.value;

            var row = Mautic.postmarkCreateMappingRow(mapping.variable, index, modules, fieldNamePrefix);
            customContainer.append(row);

            // Pre-select saved values if available
            if (savedField) {
                // Try to determine module from field name
                // For now, we'll need to load fields and see which module contains this field
                Mautic.postmarkPreSelectSavedMapping(row, savedModule, savedField);
            }
        });

        console.log('Postmark: Created interface with ' + savedMappings.length + ' mappings');
    });
};

// Pre-select saved module and field in a mapping row
Mautic.postmarkPreSelectSavedMapping = function(row, savedModule, savedValue) {
    console.log('Postmark: Trying to pre-select savedValue:', savedValue);

    var moduleSelect = row.find('.postmark-module-select');
    var fieldSelect = row.find('.postmark-field-select');
    var fieldGroup = row.find('.postmark-field-group');
    var staticInput = row.find('.postmark-static-input');
    var staticGroup = row.find('.postmark-static-group');
    var hiddenField = row.find('.postmark-hidden-field');

    // Parse the saved value - could be "module:field", "static:value", or just "field"
    var module = savedModule;
    var field = savedValue;

    if (savedValue && savedValue.indexOf(':') > 0) {
        var parts = savedValue.split(':');
        module = parts[0];
        field = parts.slice(1).join(':'); // In case the value itself contains ':'
    }

    console.log('Postmark: Parsed module:', module, 'field:', field);

    // Handle static values
    if (module === 'static') {
        console.log('Postmark: Pre-selecting static value:', field);
        moduleSelect.val('static');
        fieldGroup.css('display', 'none');
        staticGroup.css('display', 'block');
        staticInput.val(field);
        hiddenField.val('static:' + field);
        return;
    }

    if (module) {
        // Set the module dropdown
        moduleSelect.val(module);

        // Show field group, hide static group
        fieldGroup.css('display', 'block');
        staticGroup.css('display', 'none');

        // Load fields for this module
        fieldSelect.prop('disabled', true);
        fieldSelect.empty().append('<option value="">Loading fields...</option>');

        mQuery.ajax({
            url: mauticAjaxUrl + '?action=plugin:postmark:getModuleFields',
            type: 'POST',
            data: { module: module },
            dataType: 'json',
            success: function(response) {
                fieldSelect.empty().prop('disabled', false);

                if (response.success && response.fields) {
                    fieldSelect.append('<option value="">-- Select Field --</option>');

                    mQuery.each(response.fields, function(fieldAlias, fieldLabel) {
                        fieldSelect.append('<option value="' + fieldAlias + '">' + fieldLabel + '</option>');
                    });

                    // Pre-select the saved field
                    if (field) {
                        fieldSelect.val(field);
                        hiddenField.val(module + ':' + field);
                    }
                } else {
                    fieldSelect.append('<option value="">No fields found</option>');
                }
            },
            error: function() {
                fieldSelect.prop('disabled', false);
                fieldSelect.append('<option value="">Error loading fields</option>');
            }
        });
    } else if (savedValue) {
        // No module info, just show the raw saved value
        var noteHtml = '<div class="alert alert-info" style="margin-top: 10px; margin-bottom: 0;">' +
            '<i class="fa fa-info-circle"></i> Saved value: <strong>' + savedValue + '</strong><br>' +
            '<small>Please select the type and field to update this mapping.</small>' +
            '</div>';
        row.find('.form-group').last().after(noteHtml);
        hiddenField.val(savedValue);
    }
};

// Retry checking for sortable list with exponential backoff
Mautic.postmarkWaitForSortableList = function(retries, maxRetries) {
    retries = retries || 0;
    maxRetries = maxRetries || 10;

    var sortableList = mQuery('.list-sortable[id*="template_model"]');

    if (sortableList.length && sortableList.find('.sortable').length) {
        console.log('Postmark: Found sortable list with data after ' + retries + ' retries');
        Mautic.postmarkInitializeOnLoad();
    } else if (retries < maxRetries) {
        var delay = Math.min(100 * Math.pow(1.5, retries), 2000); // Max 2 seconds
        console.log('Postmark: Retry ' + (retries + 1) + '/' + maxRetries + ' in ' + delay + 'ms');
        setTimeout(function() {
            Mautic.postmarkWaitForSortableList(retries + 1, maxRetries);
        }, delay);
    } else {
        console.log('Postmark: Max retries reached, sortable list not found');
    }
};

// Run initialization when document is ready
mQuery(document).ready(function() {
    console.log('Postmark: Document ready, setting up event listeners');

    // Flag to prevent reinitialization during form submission
    window.postmarkFormSubmitting = false;

    // Initial check with delay
    setTimeout(function() {
        Mautic.postmarkInitializeOnLoad();
    }, 500);

    // Listen for Mautic modal/builder events
    if (typeof Mautic !== 'undefined') {
        // Use MutationObserver for better DOM monitoring
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    mQuery(mutation.addedNodes).each(function() {
                        var node = mQuery(this);

                        // Check if this is our sortable list or contains it
                        if (node.is('.list-sortable[id*="template_model"]') ||
                            node.find('.list-sortable[id*="template_model"]').length) {

                            // Don't reinitialize if we're in the middle of form submission
                            if (window.postmarkFormSubmitting) {
                                console.log('Postmark: Sortable list detected but form is submitting, skipping initialization');
                                return;
                            }

                            console.log('Postmark: Detected sortable list in DOM, initializing');
                            setTimeout(function() {
                                Mautic.postmarkInitializeOnLoad();
                            }, 200);
                        }
                    });
                }
            });
        });

        // Start observing the document for changes
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        // When modal is shown, start retry mechanism
        mQuery(document).on('shown.bs.modal', '.modal', function() {
            console.log('Postmark: Modal shown, starting retry mechanism');
            // Reset the flag when a new modal opens
            window.postmarkFormSubmitting = false;
            setTimeout(function() {
                Mautic.postmarkWaitForSortableList(0, 10);
            }, 300);
        });

        // When modal is hidden/closed, reset the flag
        mQuery(document).on('hidden.bs.modal', '.modal', function() {
            console.log('Postmark: Modal closed, resetting formSubmitting flag');
            window.postmarkFormSubmitting = false;
        });

        // Listen for AJAX complete to check for errors
        mQuery(document).ajaxComplete(function(event, xhr, settings) {
            // Check if this is a campaign event form submission
            if (settings.url && settings.url.indexOf('campaignevent') >= 0) {
                console.log('Postmark: AJAX complete for campaign event');
                console.log('Postmark: Status:', xhr.status);

                try {
                    var response = JSON.parse(xhr.responseText);
                    console.log('Postmark: Response:', response);

                    if (response.errors || response.error) {
                        console.error('Postmark: Form submission errors:', response.errors || response.error);
                    }
                } catch(e) {
                    // Not JSON, ignore
                }

                // Check for error messages in the modal
                setTimeout(function() {
                    var errorMessages = mQuery('.modal .alert-danger, .modal .has-error');
                    if (errorMessages.length) {
                        console.error('Postmark: Found ' + errorMessages.length + ' error messages in modal');
                        errorMessages.each(function() {
                            console.error('  - Error:', mQuery(this).text().trim());
                        });
                    }
                }, 500);
            }
        });
    }
});
