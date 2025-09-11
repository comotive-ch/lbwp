<?php declare(strict_types=1);

namespace Sprain\Tests\SwissQrBill\DataGroup\Element;

use PHPUnit\Framework\TestCase;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;

class AdditionalInformationTest extends TestCase
{
    /**
     * @dataProvider messageProvider
     */
    public function testMessage($numberOfValidations, $value)
    {
        $additionalInformation = AdditionalInformation::create($value);

        $this->assertSame($numberOfValidations, $additionalInformation->getViolations()->count());
    }

    public function messageProvider()
    {
        return [
            [0, '012345678901234567890123456'],
            [0, null],
            [0, '12345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890'],
            [1, '123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901'], // too long
        ];
    }

    /**
     * @dataProvider billInformationProvider
     */
    public function testBillInformation($numberOfValidations, $value)
    {
        $additionalInformation = AdditionalInformation::create(null, $value);

        $this->assertSame($numberOfValidations, $additionalInformation->getViolations()->count());
    }

    public function billInformationProvider()
    {
        return [
            [0, '012345678901234567890123456'],
            [0, null],
            [0, '12345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890'],
            [1, '123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901'], // too long
        ];
    }

    public function testFormattedString()
    {
        $additionalInformation = AdditionalInformation::create('message');
        $this->assertSame("message", $additionalInformation->getFormattedString());

        $additionalInformation = AdditionalInformation::create('message', 'billInformation');
        $this->assertSame("message\nbillInformation", $additionalInformation->getFormattedString());
    }

    public function testQrCodeData()
    {
        $additionalInformation = AdditionalInformation::create('message', 'billInformation');

        $expected = [
            'message',
            AdditionalInformation::TRAILER_EPD,
            'billInformation'
        ];

        $this->assertSame($expected, $additionalInformation->getQrCodeData());
    }
}