<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

class UserProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, ['label' => 'Vorname'])
            ->add('lastname', TextType::class, ['label' => 'Nachname'])
            ->add('email', EmailType::class, ['label' => 'E-Mail-Adresse', 'required' => false])
            ->add('examinerId', TextType::class, [
                'label' => 'Prüfernummer', 
                'required' => false,
                'help' => 'Ihre offizielle Nummer für Urkunden.'
            ])
            
            // Trenner für Passwort-Änderung (optional für die Optik im Formular-Objekt, meist im Template gelöst)
            
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false, // Nicht in der Entity speichern
                'required' => false,
                'label' => 'Aktuelles Passwort',
                'help' => 'Nur ausfüllen, wenn Sie das Passwort ändern möchten.',
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'first_options'  => ['label' => 'Neues Passwort'],
                'second_options' => ['label' => 'Neues Passwort wiederholen'],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints' => [
                    new Length([
                        'min' => 8,
                        'minMessage' => 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.',
                        // max length allowed by Symfony for security reasons
                        'max' => 4096,
                    ]),
                    new Regex([
                        'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/',
                        'message' => 'Das Passwort muss sicher sein: Groß- und Kleinbuchstaben, Zahlen und Sonderzeichen.'
                    ])
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}