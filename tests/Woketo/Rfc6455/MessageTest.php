<?php
/**
 * This file is a part of Woketo package.
 *
 * (c) Nekland <dev@nekland.fr>
 *
 * For the full license, take a look to the LICENSE file
 * on the root directory of this project
 */

namespace Test\Woketo\Rfc6455;

use Nekland\Woketo\Rfc6455\Frame;
use Nekland\Woketo\Rfc6455\Message;
use Nekland\Woketo\Utils\BitManipulation;

class MessageTest extends \PHPUnit_Framework_TestCase
{
    public function testItStackFramesAndReturnCompleteMessage()
    {
        /** @var Frame $frame1 */
        $frame1 = $this->prophesize('\Nekland\Woketo\Rfc6455\Frame');
        $frame1->getPayload()->willReturn('foo bar ');
        $frame1->isFinal()->willReturn(false);

        /** @var Frame $frame2 */
        $frame2 = $this->prophesize('\Nekland\Woketo\Rfc6455\Frame');
        $frame2->getPayload()->willReturn('baz');
        $frame2->isFinal()->willReturn(true);

        $message = new Message();
        $message->addFrame($frame1->reveal());

        $this->assertSame($message->isComplete(), false);

        $message->addFrame($frame2->reveal());

        $this->assertSame($message->isComplete(), true);
        $this->assertSame($message->getContent(), 'foo bar baz');
    }

    public function testItThrowErrorWhenMissingFrame()
    {
        /** @var Frame $frame1 */
        $frame1 = $this->prophesize('\Nekland\Woketo\Rfc6455\Frame');
        $frame1->getPayload()->willReturn('foo bar ');
        $frame1->isFinal()->willReturn(false);

        /** @var Frame $frame2 */
        $frame2 = $this->prophesize('\Nekland\Woketo\Rfc6455\Frame');
        $frame2->getPayload()->willReturn('baz');
        $frame2->isFinal()->willReturn(false);

        $message = new Message();
        $message->addFrame($frame1->reveal());
        $message->addFrame($frame2->reveal());

        $this->assertSame($message->isComplete(), false);
        
        $this->expectException('\Nekland\Woketo\Exception\MissingDataException');

        $message->getContent();
    }

    public function testItThrowExceptionWhenTooMuchMessages()
    {
        $message = new Message();

        $this->expectException('\Nekland\Woketo\Exception\LimitationException');

        for($i = 0; $i <= 20; $i++) {
            $frame = $this->prophesize('\Nekland\Woketo\Rfc6455\Frame');
            $frame->isFinal()->willReturn(false);
            $message->addFrame($frame->reveal());
        }
    }

    public function testItRemovesFromBuffer()
    {
        $expectedBuffer = BitManipulation::hexArrayToString(['89','8c','0e','be','06','0d','7e','d7','68','6a','2e','ce','67','74','62','d1','67','69','80','89','b3','b9','b9','7f','d5','cb','d8','18','de','dc','d7','0b','81']);
        $buffer = BitManipulation::hexArrayToString(['01','89','b1','62','d1','9d','d7','10','b0','fa','dc','07','bf','e9','80','89','8c','0e','be','06','0d','7e','d7','68','6a','2e','ce','67','74','62','d1','67','69','80','89','b3','b9','b9','7f','d5','cb','d8','18','de','dc','d7','0b','81']);

        $message = new Message();
        $message->addBuffer($buffer);
        $frame = new Frame($message->getBuffer());
        $updatedBuffer = $message->removeFromBuffer($frame);

        $this->assertSame($updatedBuffer, $expectedBuffer);
    }

    public function testItAddsAndClearsBuffer()
    {
        $data = '';
        $message = new Message();
        $message->addBuffer($data);

        $this->assertSame($data, $message->getBuffer());

        $message->clearBuffer();

        $this->assertSame('', $message->getBuffer());
    }
}
