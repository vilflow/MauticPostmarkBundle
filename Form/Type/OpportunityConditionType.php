<?php

namespace MauticPlugin\MauticPostmarkBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for "Has Matching Opportunities" condition configuration.
 */
class OpportunityConditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('field', ChoiceType::class, [
                'label' => 'mautic.postmark.form.opportunity.field',
                'choices' => $this->getOpportunityFields(),
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.postmark.form.opportunity.field.tooltip',
                ],
            ])
            ->add('operator', ChoiceType::class, [
                'label' => 'mautic.postmark.form.operator',
                'choices' => $this->getOperators(),
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('value', TextType::class, [
                'label' => 'mautic.postmark.form.value',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.postmark.form.value.tooltip',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => false,
        ]);
    }

    /**
     * Get available Opportunity fields
     */
    private function getOpportunityFields(): array
    {
        return [
            'mautic.postmark.field.opportunity.name' => 'name',
            'mautic.postmark.field.opportunity.salesStage' => 'salesStage',
            'mautic.postmark.field.opportunity.amount' => 'amount',
            'mautic.postmark.field.opportunity.opportunityType' => 'opportunityType',
            'mautic.postmark.field.opportunity.leadSource' => 'leadSource',
            'mautic.postmark.field.opportunity.presentationTypeC' => 'presentationTypeC',
            'mautic.postmark.field.opportunity.registrationTypeC' => 'registrationTypeC',
            'mautic.postmark.field.opportunity.paymentStatusC' => 'paymentStatusC',
            'mautic.postmark.field.opportunity.paymentChannelC' => 'paymentChannelC',
            'mautic.postmark.field.opportunity.reviewResultC' => 'reviewResultC',
            'mautic.postmark.field.opportunity.formTypeC' => 'formTypeC',
            'mautic.postmark.field.opportunity.closeDateC' => 'closeDateC',
            'mautic.postmark.field.opportunity.createdAt' => 'createdAt',
            'mautic.postmark.field.opportunity.updatedAt' => 'updatedAt',
        ];
    }

    /**
     * Get available operators
     */
    private function getOperators(): array
    {
        return [
            'mautic.postmark.operator.eq' => '=',
            'mautic.postmark.operator.neq' => '!=',
            'mautic.postmark.operator.gt' => 'gt',
            'mautic.postmark.operator.gte' => 'gte',
            'mautic.postmark.operator.lt' => 'lt',
            'mautic.postmark.operator.lte' => 'lte',
            'mautic.postmark.operator.like' => 'like',
            'mautic.postmark.operator.contains' => 'contains',
            'mautic.postmark.operator.in' => 'in',
            'mautic.postmark.operator.empty' => 'empty',
            'mautic.postmark.operator.not_empty' => '!empty',
        ];
    }
}
