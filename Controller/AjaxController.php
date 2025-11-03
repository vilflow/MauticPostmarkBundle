<?php

namespace MauticPlugin\MauticPostmarkBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as BaseAjaxController;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\MauticPostmarkBundle\Service\PostmarkApiService;
use Symfony\Component\HttpFoundation\Request;

class AjaxController extends BaseAjaxController
{
    public function getTemplatesAction(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $dataArray = ['success' => 0, 'templates' => []];
        
        $serverToken = trim((string) $request->request->get('server_token', ''));
        
        if (empty($serverToken)) {
            $dataArray['message'] = 'Server token is required';
            return $this->sendJsonResponse($dataArray);
        }

        try {
            // Create the service manually since service locator has limited access
            $postmarkApiService = new PostmarkApiService();
            
            [$success, $templates, $error] = $postmarkApiService->getTemplateList($serverToken);
            
            if (!$success) {
                $dataArray['message'] = $error ?? 'Failed to fetch templates';
                return $this->sendJsonResponse($dataArray);
            }

            $choices = $postmarkApiService->formatTemplateChoices($templates);

            $dataArray['success'] = 1;
            $dataArray['templates'] = $choices;
            $dataArray['message'] = 'Templates fetched successfully';

            return $this->sendJsonResponse($dataArray);

        } catch (\Exception $e) {
            $dataArray['message'] = 'Error fetching templates: ' . $e->getMessage();
            return $this->sendJsonResponse($dataArray);
        }
    }

    public function getTemplateVariablesAction(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $dataArray = ['success' => 0, 'variables' => []];
        
        $serverToken = trim((string) $request->request->get('server_token', ''));
        $templateAlias = trim((string) $request->request->get('template_alias', ''));
        
        if (empty($serverToken)) {
            $dataArray['message'] = 'Server token is required';
            return $this->sendJsonResponse($dataArray);
        }
        
        if (empty($templateAlias)) {
            $dataArray['message'] = 'Template alias is required';
            return $this->sendJsonResponse($dataArray);
        }

        try {
            // Create the service manually since service locator has limited access
            $postmarkApiService = new PostmarkApiService();
            
            [$success, $template, $error] = $postmarkApiService->getTemplate($serverToken, $templateAlias);
            
            if (!$success) {
                $dataArray['message'] = $error ?? 'Failed to fetch template';
                return $this->sendJsonResponse($dataArray);
            }

            $variables = $postmarkApiService->extractTemplateVariables($template);

            $dataArray['success'] = 1;
            $dataArray['variables'] = $variables;
            $dataArray['message'] = 'Template variables fetched successfully';
            $dataArray['template_name'] = $template['Name'] ?? '';

            return $this->sendJsonResponse($dataArray);

        } catch (\Exception $e) {
            $logFile = dirname(__DIR__) . '/postmark_error.log';
            $logLine = sprintf(
                "[%s] getTemplateVariablesAction error: %s%s",
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $e->getMessage(),
                PHP_EOL
            );
            @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
            $dataArray['message'] = 'Error fetching template variables: ' . $e->getMessage();
            return $this->sendJsonResponse($dataArray);
        }
    }

    /**
     * Get available modules/entities for field mapping
     */
    public function getModulesAction(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $dataArray = ['success' => 1, 'modules' => []];

        try {
            // Define available modules for field mapping
            $modules = [
                'lead' => 'Contact',
                'company' => 'Company',
                'event' => 'Event',
                'opportunity' => 'Opportunity',
                'note' => 'Note',
            ];

            $dataArray['modules'] = $modules;
            $dataArray['message'] = 'Modules fetched successfully';

            return $this->sendJsonResponse($dataArray);

        } catch (\Exception $e) {
            $dataArray['success'] = 0;
            $dataArray['message'] = 'Error fetching modules: ' . $e->getMessage();
            return $this->sendJsonResponse($dataArray);
        }
    }

    /**
     * Get fields for a specific module
     */
    public function getModuleFieldsAction(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $dataArray = ['success' => 0, 'fields' => []];

        $module = trim((string) $request->request->get('module', ''));

        if (empty($module)) {
            $dataArray['message'] = 'Module is required';
            return $this->sendJsonResponse($dataArray);
        }

        try {
            $formattedFields = [];

            // Handle standard Mautic entities with FieldModel (lead/company)
            if (in_array($module, ['lead', 'company'])) {
                /** @var FieldModel $fieldModel */
                $fieldModel = $this->getModel('lead.field');

                if (!$fieldModel) {
                    $dataArray['message'] = 'Field model not available';
                    return $this->sendJsonResponse($dataArray);
                }

                // Get fields for the specified object type
                $fields = $fieldModel->getFieldList(
                    false, // don't group by category
                    true,  // alphabetical
                    ['isPublished' => true, 'object' => $module]
                );

                // Format fields for dropdown: ['field_alias' => 'Field Label']
                foreach ($fields as $group => $groupFields) {
                    if (is_array($groupFields)) {
                        foreach ($groupFields as $alias => $field) {
                            $label = is_array($field) ? ($field['label'] ?? $alias) : $field;
                            $formattedFields[$alias] = $label;
                        }
                    } else {
                        // If not grouped, field itself contains the data
                        $formattedFields[$group] = $groupFields;
                    }
                }
            }
            // Handle custom plugin entities (event, opportunity, note)
            else {
                error_log('Postmark: Calling getCustomEntityFields for module: ' . $module);
                $formattedFields = $this->getCustomEntityFields($module);
                error_log('Postmark: getCustomEntityFields returned ' . count($formattedFields) . ' fields');
                error_log('Postmark: Fields array: ' . print_r($formattedFields, true));

                if (empty($formattedFields)) {
                    error_log('Postmark: Fields array is empty, returning error');
                    $dataArray['message'] = 'No fields found for this module';
                    return $this->sendJsonResponse($dataArray);
                }
            }

            error_log('Postmark: Preparing success response with ' . count($formattedFields) . ' fields');
            $dataArray['success'] = 1;
            $dataArray['fields'] = $formattedFields;
            $dataArray['message'] = 'Fields fetched successfully';

            error_log('Postmark: Returning JSON response');
            return $this->sendJsonResponse($dataArray);

        } catch (\Exception $e) {
            $dataArray['message'] = 'Error fetching fields: ' . $e->getMessage();
            return $this->sendJsonResponse($dataArray);
        }
    }

    /**
     * Get fields for custom plugin entities using entity metadata
     */
    private function getCustomEntityFields(string $module): array
    {
        $fields = [];

        // Map module names to entity classes
        $entityMap = [
            'event' => \MauticPlugin\MauticEventsBundle\Entity\Event::class,
            'opportunity' => \MauticPlugin\MauticOpportunitiesBundle\Entity\Opportunity::class,
            'note' => \MauticPlugin\MauticNotesBundle\Entity\Note::class,
        ];

        if (!isset($entityMap[$module])) {
            error_log('Postmark: Module not in entity map: ' . $module);
            return [];
        }

        $entityClass = $entityMap[$module];

        // Check if entity class exists
        if (!class_exists($entityClass)) {
            error_log('Postmark: Entity class does not exist: ' . $entityClass);
            return [];
        }

        error_log('Postmark: Processing fields for entity: ' . $entityClass);

        // Try Doctrine metadata first
        try {
            $em = $this->container->get('doctrine.orm.entity_manager');
            $metadata = $em->getClassMetadata($entityClass);
            $fieldNames = $metadata->getFieldNames();

            error_log('Postmark: Doctrine found ' . count($fieldNames) . ' fields');

            foreach ($fieldNames as $fieldName) {
                // Skip internal fields
                if (in_array($fieldName, ['id', 'createdBy', 'modifiedBy', 'checkedOut', 'checkedOutBy', 'deletedBy', 'isPublished', 'dateAdded', 'dateModified'])) {
                    continue;
                }

                // Convert camelCase to Title Case for label
                $label = ucwords(preg_replace('/([A-Z])/', ' $1', $fieldName));
                $label = trim($label);

                $fields[$fieldName] = $label;
            }

            error_log('Postmark: After filtering, kept ' . count($fields) . ' fields');

        } catch (\Exception $e) {
            error_log('Postmark: Doctrine metadata failed: ' . $e->getMessage());

            // Fallback to PHP Reflection
            error_log('Postmark: Trying PHP Reflection fallback');
            $fields = $this->getFieldsViaReflection($entityClass);
        }

        // Sort alphabetically by label
        if (!empty($fields)) {
            asort($fields);
        }

        return $fields;
    }

    /**
     * Fallback method to get fields using PHP Reflection
     */
    private function getFieldsViaReflection(string $entityClass): array
    {
        $fields = [];

        try {
            $reflection = new \ReflectionClass($entityClass);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED);

            error_log('Postmark: Reflection found ' . count($properties) . ' properties');

            foreach ($properties as $property) {
                $propertyName = $property->getName();

                // Skip internal fields
                if (in_array($propertyName, ['id', 'createdBy', 'modifiedBy', 'checkedOut', 'checkedOutBy', 'deletedBy', 'isPublished', 'dateAdded', 'dateModified', 'changes', 'eventContacts', 'new'])) {
                    continue;
                }

                // Skip collections and relations
                $docComment = $property->getDocComment();
                if ($docComment && (strpos($docComment, 'Collection') !== false || strpos($docComment, 'ManyToOne') !== false || strpos($docComment, 'OneToMany') !== false)) {
                    continue;
                }

                // Convert camelCase to Title Case for label
                $label = ucwords(preg_replace('/([A-Z])/', ' $1', $propertyName));
                $label = trim($label);

                $fields[$propertyName] = $label;
            }

            error_log('Postmark: Reflection kept ' . count($fields) . ' fields after filtering');

        } catch (\Exception $e) {
            error_log('Postmark: Reflection also failed: ' . $e->getMessage());
        }

        return $fields;
    }
}
