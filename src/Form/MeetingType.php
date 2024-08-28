<?php

namespace App\Form;

use App\Entity\Meeting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;

class MeetingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title')
            ->add('detail')
            ->add('date', DateTimeType::class, [
                'widget' => 'single_text',
            ])
            ->add('alternativeDate', DateTimeType::class, [
                'widget' => 'single_text',
                'required' => false, // Car c'est une date alternative, elle peut être optionnelle
            ])
            ->add('place');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Meeting::class,
        ]);
    }
}
