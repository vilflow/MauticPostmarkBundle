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
    
    if (!templateAlias || !serverToken) {
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
            if (response.success) {
                if (response.variables && response.variables.length > 0) {
                    Mautic.postmarkPopulateTemplateModel(form, response.variables);
                } else {
                    // Still clear existing fields even when no variables found
                    Mautic.postmarkPopulateTemplateModel(form, []);
                }
            } else {
                // Clear fields on error too
                Mautic.postmarkPopulateTemplateModel(form, []);
            }
        },
        error: function() {
            // Error - clear existing fields
            var form = mQuery(templateSelectElement).closest('form');
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

// Function to populate the SortableListType with template variables
Mautic.postmarkPopulateTemplateModel = function(form, variables) {
    // Find the template model container - try multiple approaches
    var templateModelContainer = form.find('[id*="template_model"]').closest('.form-group');
    
    if (!templateModelContainer.length) {
        templateModelContainer = form.find('[data-toggle="sortablelist"]');
    }
    
    if (!templateModelContainer.length) {
        templateModelContainer = form.find('.sortable-list').closest('.form-group');
    }
    
    if (!templateModelContainer.length) {
        // Last resort - look for any form group containing "template" or "model"
        form.find('.form-group').each(function() {
            var labelText = mQuery(this).find('label').text().toLowerCase();
            if (labelText.indexOf('template') >= 0 && labelText.indexOf('variable') >= 0) {
                templateModelContainer = mQuery(this);
                return false; // break
            }
        });
    }
    
    if (!templateModelContainer.length) {
        return;
    }
    
    // CLEAR EXISTING VARIABLES FIRST
    Mautic.postmarkClearTemplateModel(templateModelContainer);
    
    // Wait a moment for clearing to complete, then add new variables (if any)
    setTimeout(function() {
        if (variables && variables.length > 0) {
            Mautic.postmarkAddTemplateVariables(templateModelContainer, variables);
        }
    }, 500); // 500ms delay to ensure clearing is complete
};

// Separate function to handle adding the variables  
Mautic.postmarkAddTemplateVariables = function(templateModelContainer, variables) {
    var addButton = templateModelContainer.find('.btn-add-item');
    
    if (!addButton.length) {
        return;
    }
    
    // Add each variable one by one
    variables.forEach(function(variable, index) {
        setTimeout(function() {
            // Click the add button to create a new item
            addButton[0].click();
            
            // Wait for the new item to be created, then populate it
            setTimeout(function() {
                // Find all sortable items
                var sortableItems = templateModelContainer.find('.sortable');
                
                if (sortableItems.length > 0) {
                    // Get the last (newest) sortable item
                    var lastItem = sortableItems.last();
                    
                    // Find the label and value inputs within this item
                    var labelInput = lastItem.find('input.sortable-label');
                    var valueInput = lastItem.find('input.sortable-value');
                    
                    if (labelInput.length && valueInput.length) {
                        // Set the label (variable name)
                        labelInput.val(variable);
                        labelInput.trigger('change');
                        
                        // Leave value field empty but set a helpful placeholder
                        valueInput.val(''); // Keep value empty
                        valueInput.attr('placeholder', 'Enter value for ' + variable);
                        valueInput.trigger('change');
                    }
                }
            }, 200); // Small delay to let DOM update
        }, index * 400); // Stagger the additions with more delay
    });
};