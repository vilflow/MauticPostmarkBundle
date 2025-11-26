<?php

namespace MauticPlugin\MauticPostmarkBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for "Has Matching Notes" condition configuration.
 */
class NoteConditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('field', ChoiceType::class, [
                'label' => 'mautic.postmark.form.note.field',
                'choices' => $this->getNoteFields(),
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'tooltip' => 'mautic.postmark.form.note.field.tooltip',
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
     * Get available Note fields
     */
    private function getNoteFields(): array
    {
        return [
            'mautic.postmark.field.note.name' => 'name',
            'mautic.postmark.field.note.description' => 'description',
            'mautic.postmark.field.note.embedFlag' => 'embedFlag',
            'mautic.postmark.field.note.newsletterFormC' => 'newsletterFormC',
            'mautic.postmark.field.note.popupFormC' => 'popupFormC',
            'mautic.postmark.field.note.portalFlag' => 'portalFlag',
            'mautic.postmark.field.note.visaFormC' => 'visaFormC',
            'mautic.postmark.field.note.createdAt' => 'createdAt',
            'mautic.postmark.field.note.updatedAt' => 'updatedAt',
            'mautic.postmark.field.note.dateEntered' => 'dateEntered',
            'mautic.postmark.field.note.dateModified' => 'dateModified',
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
