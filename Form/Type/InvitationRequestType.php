<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\ApiPortalBundle\Form\Type;

use Klipper\Component\Form\Doctrine\Type\EntityType;
use Klipper\Component\Portal\Model\PortalInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Expression;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class InvitationRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('portal', EntityType::class, [
                'class' => PortalInterface::class,
                'required' => true,
                'constraints' => [
                    new NotBlank(),
                    new Expression('value && value.isPortalEnabled()'),
                ],
            ])
            ->add('email', EmailType::class, [
                'mapped' => false,
                'required' => true,
                'constraints' => [
                    new NotBlank(),
                ],
            ])
        ;
    }
}
