<?php declare(strict_types=1);

namespace Sprain\SwissQrBill\Validator;

use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

trait SelfValidatableTrait
{
    /** @var ValidatorInterface */
    private $validator;

    public function getViolations(): ConstraintViolationListInterface
    {
        if (null == $this->validator) {
            $this->validator = Validation::createValidatorBuilder()
                ->addMethodMapping('loadValidatorMetadata')
                ->getValidator();
        }

        return $this->validator->validate($this);
    }

    public function isValid(): bool
    {
        if (0 == $this->getViolations()->count()) {
            return true;
        }

        return false;
    }
}
