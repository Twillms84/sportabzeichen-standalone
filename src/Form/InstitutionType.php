<?php

namespace App\Form;

use App\Entity\Institution;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InstitutionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name der Institution / Schule',
                'attr' => ['placeholder' => 'z.B. Gymnasium Beispielstadt']
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Art der Institution',
                'choices' => [
                    'Schule' => 'SCHOOL',
                    'Verein / Club' => 'CLUB',
                    'Sonstige' => 'OTHER',
                ],
                'attr' => ['class' => 'form-select']
            ])
            ->add('registrarEmail', EmailType::class, [
                'label' => 'Offizielle E-Mail (Registrar)',
                'help' => 'Haupt-Identifikator für die Verwaltung.',
                'attr' => ['readonly' => true] // Sicherheit: Registrar-Email sollte man meist nicht selbst ändern
            ])
            ->add('contactPerson', TextType::class, [
                'label' => 'Ansprechpartner / Verantwortlicher',
                'required' => false,
                'attr' => ['placeholder' => 'Vorname Nachname']
            ])
            ->add('street', TextType::class, [
                'label' => 'Straße & Hausnummer',
                'required' => false,
            ])
            ->add('zip', TextType::class, [
                'label' => 'PLZ',
                'required' => false,
            ])
            ->add('city', TextType::class, [
                'label' => 'Stadt',
                'required' => false,
            ])
            ->add('identifier', TextType::class, [
                'label' => 'Technischer Identifier (Optional)',
                'required' => false,
                'attr' => ['placeholder' => 'z.B. Schulnummer oder SSO-ID'],
                'help' => 'Internes Kürzel für technische Anbindungen.'
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Institution::class,
        ]);
    }
}