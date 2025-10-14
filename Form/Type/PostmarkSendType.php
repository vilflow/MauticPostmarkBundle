<?php

namespace MauticPlugin\MauticPostmarkBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\SortableListType;
use MauticPlugin\MauticPostmarkBundle\Service\PostmarkApiService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class PostmarkSendType extends AbstractType
{
    private PostmarkApiService $postmarkApiService;
    private ParameterBagInterface $parameterBag;

    public function __construct(PostmarkApiService $postmarkApiService, ParameterBagInterface $parameterBag)
    {
        $this->postmarkApiService = $postmarkApiService;
        $this->parameterBag = $parameterBag;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Get account token from environment
        $envAccountToken = $this->getAccountTokenFromEnv();
        
        // Authentication & Configuration Section
        // $builder
        //     ->add('account_token', PasswordType::class, [
        //         'label'       => 'mautic.postmark.form.account_token',
        //         'required'    => false,
        //         'data'        => $envAccountToken,
        //         'attr'        => [
        //             'class'           => 'form-control',
        //             'autocomplete'    => 'new-password',
        //             'data-toggle'     => 'tooltip',
        //             'data-placement'  => 'top',
        //             'title'           => 'mautic.postmark.form.account_token.tooltip',
        //             'readonly'        => !empty($envAccountToken),
        //         ],
        //     ]);

        // Pre-populate server choices if account token is available
        $serverChoices = [];
        if (!empty($envAccountToken)) {
            try {
                [$success, $servers] = $this->postmarkApiService->getServerList($envAccountToken);
                if ($success && !empty($servers)) {
                    $serverChoices = $this->postmarkApiService->formatServerChoices($servers);
                }
            } catch (\Exception $e) {
                // Log the error or handle it gracefully
                // For now, just continue with empty choices
            }
        }

        $builder
            ->add('server_token', ChoiceType::class, [
                'label'       => 'mautic.postmark.form.server_token',
                'required'    => true,
                'choices'     => $serverChoices,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.postmark.form.server_token.required']),
                ],
                'attr'        => [
                    'class'           => 'form-control postmark-server-select',
                    'data-toggle'     => 'tooltip',
                    'data-placement'  => 'top',
                    'title'           => 'mautic.postmark.form.server_token.tooltip',
                    'onchange'        => 'Mautic.postmarkLoadTemplates(this)',
                ],
                'placeholder' => empty($serverChoices) ? 'mautic.postmark.form.server_token.no_servers' : 'mautic.postmark.form.server_token.placeholder',
            ]);

        // Email Configuration Section  
        $builder
            ->add('from_email', TextType::class, [
                'label'       => 'mautic.postmark.form.from_email',
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.postmark.form.from_email.required']),
                ],
                'attr'        => [
                    'class'           => 'form-control',
                    'placeholder'     => 'sender@yourdomain.com',
                    'data-toggle'     => 'tooltip',
                    'data-placement'  => 'top',
                    'title'           => 'mautic.postmark.form.from_email.tooltip',
                ],
            ])
            ->add('to_email', TextType::class, [
                'label'       => 'mautic.postmark.form.to_email',
                'required'    => true,
                'constraints' => [
                    new NotBlank(['message' => 'mautic.postmark.form.to_email.required']),
                ],
                'attr'        => [
                    'class'           => 'form-control',
                    'placeholder'     => '{contactfield=email} or user@example.com',
                    'data-toggle'     => 'tooltip',
                    'data-placement'  => 'top',
                    'title'           => 'mautic.postmark.form.to_email.tooltip',
                ],
            ]);

        // Postmark Template Section
        $builder
            ->add('template_alias', ChoiceType::class, [
                'label'       => 'mautic.postmark.form.template_alias',
                'required'    => true,
                'choices'     => [],
                'constraints' => [
                    new NotBlank(['message' => 'mautic.postmark.form.template_alias.required']),
                ],
                'attr'        => [
                    'class'           => 'form-control postmark-template-select',
                    'data-toggle'     => 'tooltip',
                    'data-placement'  => 'top',
                    'title'           => 'mautic.postmark.form.template_alias.tooltip',
                    'onchange'        => 'Mautic.postmarkLoadTemplateVariables(this)',
                ],
                'placeholder' => 'mautic.postmark.form.template_alias.placeholder',
            ])
            ->add('template_model', SortableListType::class, [
                'label'           => 'mautic.postmark.form.template_model',
                'required'        => false,
                'option_required' => false,
                'with_labels'     => true,
                'attr'            => [
                    'class'           => 'form-control',
                    'data-toggle'     => 'tooltip',
                    'data-placement'  => 'top',
                    'title'           => 'mautic.postmark.form.template_model.tooltip',
                ],
                'label_attr'      => [
                    'data-toggle'     => 'tooltip',
                    'data-placement'  => 'top',
                    'title'           => 'mautic.postmark.form.template_model.help',
                ],
                // Set to true to persist as a flat key=>value array instead of [list => [...]]
                // 'key_value_pairs' => true,
            ]);

        // Add form event to populate server choices dynamically
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'onPreSetData']);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'onPreSubmit']);
    }

    private function getAccountTokenFromEnv(): string
    {
        // Try multiple environment variable names
        $envVars = [
            'POSTMARK_ACCOUNT_TOKEN',
            'POSTMARK_ACCOUNT_API_TOKEN', 
            'MAUTIC_POSTMARK_ACCOUNT_TOKEN'
        ];

        foreach ($envVars as $envVar) {
            try {
                // First try to get from environment directly
                $token = $_ENV[$envVar] ?? $_SERVER[$envVar] ?? '';
                if (!empty($token)) {
                    return $token;
                }
                
                // Then try parameter bag if the env() processor is configured
                if ($this->parameterBag->has('env(' . $envVar . ')')) {
                    $token = $this->parameterBag->get('env(' . $envVar . ')');
                    if (!empty($token) && $token !== $envVar) {
                        return $token;
                    }
                }
            } catch (\Exception $e) {
                // Skip this environment variable and try the next one
                continue;
            }
        }

        return '';
    }

    public function onPreSetData(FormEvent $event): void
    {
        $data = $event->getData();
        $form = $event->getForm();

        $accountToken = $data['account_token'] ?? '';
        if (!empty($accountToken)) {
            $this->updateServerChoices($form, $accountToken);
        }
        
        $serverToken = $data['server_token'] ?? '';
        if (!empty($serverToken)) {
            $this->updateTemplateChoices($form, $serverToken);
        }
    }

    public function onPreSubmit(FormEvent $event): void
    {
        $data = $event->getData();
        $form = $event->getForm();

        $accountToken = $data['account_token'] ?? '';
        if (!empty($accountToken)) {
            $this->updateServerChoices($form, $accountToken);
        }
        
        $serverToken = $data['server_token'] ?? '';
        if (!empty($serverToken)) {
            $this->updateTemplateChoices($form, $serverToken);
        }
    }

    private function updateServerChoices($form, string $accountToken): void
    {
        $choices = [];
        
        try {
            [$success, $servers] = $this->postmarkApiService->getServerList($accountToken);
            if ($success && !empty($servers)) {
                $choices = $this->postmarkApiService->formatServerChoices($servers);
            }
        } catch (\Exception $e) {
            // Log the error or handle it gracefully
            // For now, just continue with empty choices
        }

        $form->add('server_token', ChoiceType::class, [
            'label'       => 'mautic.postmark.form.server_token',
            'required'    => true,
            'choices'     => $choices,
            'constraints' => [
                new NotBlank(['message' => 'mautic.postmark.form.server_token.required']),
            ],
            'attr'        => [
                'class'           => 'form-control postmark-server-select',
                'data-toggle'     => 'tooltip',
                'data-placement'  => 'top',
                'title'           => 'mautic.postmark.form.server_token.tooltip',
                'onchange'        => 'Mautic.postmarkLoadTemplates(this)',
            ],
            'placeholder' => empty($choices) ? 'mautic.postmark.form.server_token.no_servers' : 'mautic.postmark.form.server_token.placeholder',
        ]);
    }

    private function updateTemplateChoices($form, string $serverToken): void
    {
        $choices = [];
        
        try {
            [$success, $templates] = $this->postmarkApiService->getTemplateList($serverToken);
            if ($success && !empty($templates)) {
                $choices = $this->postmarkApiService->formatTemplateChoices($templates);
            }
        } catch (\Exception $e) {
            // Log the error or handle it gracefully
            // For now, just continue with empty choices
        }

        $form->add('template_alias', ChoiceType::class, [
            'label'       => 'mautic.postmark.form.template_alias',
            'required'    => true,
            'choices'     => $choices,
            'constraints' => [
                new NotBlank(['message' => 'mautic.postmark.form.template_alias.required']),
            ],
            'attr'        => [
                'class'           => 'form-control postmark-template-select',
                'data-toggle'     => 'tooltip',
                'data-placement'  => 'top',
                'title'           => 'mautic.postmark.form.template_alias.tooltip',
                'onchange'        => 'Mautic.postmarkLoadTemplateVariables(this)',
            ],
            'placeholder' => empty($choices) ? 'mautic.postmark.form.template_alias.no_templates' : 'mautic.postmark.form.template_alias.placeholder',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'translation_domain' => 'messages',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'postmark_send';
    }
}
