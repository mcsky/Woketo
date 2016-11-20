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

use Nekland\Woketo\Exception\Frame\IncompleteFrameException;
use Nekland\Woketo\Rfc6455\Frame;
use Nekland\Woketo\Rfc6455\FrameFactory;
use Nekland\Woketo\Rfc6455\Message;
use Nekland\Woketo\Rfc6455\MessageHandler\PingFrameHandler;
use Nekland\Woketo\Rfc6455\MessageHandler\Rfc6455MessageHandlerInterface;
use Nekland\Woketo\Rfc6455\MessageProcessor;
use Nekland\Woketo\Utils\BitManipulation;
use Prophecy\Argument;
use React\Socket\ConnectionInterface;

class MessageProcessorTest extends \PHPUnit_Framework_TestCase
{
    private $socket;

    public function setUp()
    {
        parent::setUp();

        $this->socket = $this->prophesize(ConnectionInterface::class);
    }

    public function testItBuildMessagesUsingMessageClass()
    {
        $processor = new MessageProcessor();

        /** @var Message $message */
        $messages = iterator_to_array($processor->onData(
            // Hello normal frame
            BitManipulation::hexArrayToString(['81', '05', '48', '65', '6c', '6c', '6f']),
            $this->socket->reveal()
        ));

        $this->assertInstanceOf(Message::class, $messages[0]);
        $this->assertSame('Hello', $messages[0]->getContent());
    }

    public function testItBuildManyMessagesWithOnlyOneFrameData()
    {
        $multipleFrameData = BitManipulation::hexArrayToString(
            '01', '03', '48', '65', '6c', // Data part 1
            '80', '02', '6c', '6f',       // Data part 2
            '81', '85', '37', 'fa', '21', '3d', '7f', '9f', '4d', '51', '58' // Another message (Hello frame)
        );

        $processor = new MessageProcessor();

        $messages = iterator_to_array($processor->onData($multipleFrameData, $this->socket->reveal()));

        $this->assertSame(count($messages), 2);
        $this->assertSame($messages[1]->getContent(), 'Hello');
    }

    public function testItContinueFrameEvaluationAfterControlFrame()
    {
        $multipleFrameData = BitManipulation::hexArrayToString(
            '89', '00',                                                       // Ping
            '81', '85', '37', 'fa', '21', '3d', '7f', '9f', '4d', '51', '58', // Another message (Hello frame)
            '89', '00'                                                        // Ping
        );

        $processor = new MessageProcessor();
        $processor->addHandler(new PingFrameHandler());

        /** @var Message[] $messages */
        $messages = iterator_to_array($processor->onData($multipleFrameData, $this->socket->reveal()));

        $this->assertSame(count($messages), 3);
        $this->assertSame($messages[1]->getContent(), 'Hello');
        $this->assertSame($messages[0]->getFirstFrame()->getOpcode(), Frame::OP_PING);
        $this->assertSame($messages[2]->getFirstFrame()->getOpcode(), Frame::OP_PING);
    }

    public function testItBuildPartialMessage()
    {
        $processor = new MessageProcessor();
        $socket = $this->socket->reveal();

        $messages = iterator_to_array($processor->onData(
            // "Hel" normal frame unmasked
            BitManipulation::hexArrayToString(['01', '03', '48', '65', '6c']),
            $socket
        ));

        $this->assertSame($messages[0]->isComplete(), false);

        iterator_to_array($processor->onData(
            // "lo" normal frame unmasked
            BitManipulation::hexArrayToString(['80', '02', '6c', '6f']),
            $socket,
            $messages[0]
        ));

        $this->assertSame($messages[0]->isComplete(), true);
        $this->assertSame($messages[0]->getContent(), 'Hello');
    }

    public function testItBuildOnlyCompleteMessagesOrYieldLastOnly()
    {
        $processor = new MessageProcessor();
        $socket = $this->socket->reveal();

        $messages = iterator_to_array($processor->onData(
            // "Hel" and "lo" normal frame unmasked
            BitManipulation::hexArrayToString('01', '03', '48', '65', '6c', '80', '02', '6c', '6f'),
            $socket
        ));
        $this->assertSame(count($messages), 1);
    }

    public function testItHandleSpecialMessagesWithHandler()
    {
        $processor = new MessageProcessor();
        $this->assertSame($processor->addHandler(new class() implements Rfc6455MessageHandlerInterface {
            public function supports(Message $message)
            {
                return $message->getFirstFrame()->getOpcode() === Frame::OP_CLOSE;
            }

            public function process(Message $message, MessageProcessor $messageProcessor, ConnectionInterface $socket)
            {
                $messageProcessor->write((new FrameFactory())->createCloseFrame(), $socket);
            }
        }), $processor);

        $this->socket->write(Argument::cetera())->shouldBeCalled();

        $messages = iterator_to_array($processor->onData(
            BitManipulation::hexArrayToString(['88', '02', '03', 'E8']),
            $this->socket->reveal()
        ));

        $this->assertSame($messages[0]->getOpcode(), Frame::OP_CLOSE);
    }

    public function testItReturnTheFrameFactory()
    {
        $processor = new MessageProcessor();

        $this->assertInstanceOf(FrameFactory::class, $processor->getFrameFactory());
    }

    public function testItWritesFrames()
    {
        $this->socket->write(BitManipulation::hexArrayToString(['81', '05', '48', '65', '6c', '6c', '6f']))->shouldBeCalled();
        $this->socket->write('foo')->shouldBeCalled();
        $frame = $this->prophesize(Frame::class);
        $frame->getRawData()->willReturn('foo');

        $processor = new MessageProcessor();
        $processor->write('Hello', $this->socket->reveal());
        $processor->write($frame->reveal(), $this->socket->reveal());
    }

    public function testItCatchesLimitationException()
    {
        $framefactory = $this->prophesize(FrameFactory::class);
        $framefactory
            ->createCloseFrame(Frame::CLOSE_TOO_BIG_TO_PROCESS)
            ->willReturn(new Frame(BitManipulation::hexArrayToString(['88', '02', '03', 'E8'])))
            ->shouldBeCalled();
        $processor = new MessageProcessor($framefactory->reveal());

        $messages = iterator_to_array($processor->onData(
            BitManipulation::hexArrayToString(['89','7f','ff','ff','ff', 'ff', 'ff','ff','ff','ff']),
            $this->socket->reveal()
        ));

        $this->assertSame($messages, []);
    }

    public function testItCatchesTooBigControlFrameException()
    {
        $framefactory = $this->prophesize(FrameFactory::class);
        $framefactory
            ->createCloseFrame(Frame::CLOSE_PROTOCOL_ERROR)
            ->willReturn(new Frame(BitManipulation::hexArrayToString(['88', '02', '03', 'E8'])))
            ->shouldBeCalled();
        $processor = new MessageProcessor($framefactory->reveal());

        $messages = iterator_to_array($processor->onData(
            BitManipulation::hexArrayToString(['89','7e','00','7e','00','00 ','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','23','a9','af','ec','ec','ec','ec','ec','ec']),
            $this->socket->reveal()
        ));

        $this->assertSame($messages, []);
    }

    public function testItCatchesNotGoodEncodingException()
    {
        $framefactory = $this->prophesize(FrameFactory::class);
        $framefactory
            ->createCloseFrame(Frame::CLOSE_INCOHERENT_DATA)
            ->willReturn(new Frame(BitManipulation::hexArrayToString(['88','02','03','ef'])))
            ->shouldBeCalled();
        $processor = new MessageProcessor($framefactory->reveal());

        $messages = iterator_to_array($processor->onData(
            BitManipulation::hexArrayToString(['81','94','e8','e7','96','54','26','5d','77','e9','51','28','15','9a','54','29','23','b9','48','67','f3','30','81','93','f3','30']),
            $this->socket->reveal()
        ));

        $this->assertSame($messages, []);
    }

    public function testItSupportsMessageInManyFrames()
    {
        $multipleFrameData = BitManipulation::hexArrayToString(
            '01', '03', '48', '65', '6c', // Data part 1
            '80', '02', '6c', '6f'        // Data part 2
        );

        $frame1 = new Frame(BitManipulation::hexArrayToString('01', '03', '48', '65', '6c'));
        $frame2 = new Frame(BitManipulation::hexArrayToString('80', '02', '6c', '6f'));

        $framefactory = $this->prophesize(FrameFactory::class);
        $processor = new MessageProcessor($framefactory->reveal());

        $expectedMessage = new Message();
        $expectedMessage->addFrame($frame1);
        $expectedMessage->addFrame($frame2);

        $messages = iterator_to_array($processor->onData(
            $multipleFrameData,
            $this->socket->reveal()
        ));

        $this->assertSame($messages[0]->getContent(), $expectedMessage->getContent());
        $this->assertTrue($messages[0]->isComplete());
        $this->assertSame($messages[0]->isComplete(), $expectedMessage->isComplete());
        $this->assertSame($messages[0]->getOpcode(), Frame::OP_TEXT);
        $this->assertSame($messages[0]->getContent(), 'Hello');
        $this->assertSame(count($messages[0]->getFrames()), 2);
    }

    public function testItThrowsIncompleteExceptionAndSpecifiesIncompleteMessage()
    {
        $this->expectException(IncompleteFrameException::class);

        $incompleteFrame = BitManipulation::hexArrayToString('81', '85', '37', 'fa', '21', '3d');

        $frame1 = new Frame($incompleteFrame);

        $framefactory = $this->prophesize(FrameFactory::class);
        $processor = new MessageProcessor($framefactory->reveal());

        $expectedMessage = new Message();
        $expectedMessage->addFrame($frame1);

        $messages = iterator_to_array($processor->onData(
            $incompleteFrame,
            $this->socket->reveal()
        ));

        $this->assertFalse($messages[0]->isComplete());
    }

    public function testItProcessesOnePingBetweenTwoFragmentedTextMessages()
    {
        $multipleFrameData = BitManipulation::hexArrayToString(['01','89','b1','62','d1','9d','d7','10','b0','fa','dc','07','bf','e9','80','89','8c','0e','be','06','0d','7e','d7','68','6a','2e','ce','67','74','62','d1','67','69','80','89','b3','b9','b9','7f','d5','cb','d8','18','de','dc','d7','0b','81']);

        $frame1 = new Frame(BitManipulation::hexArrayToString('01', '03', '48', '65', '6c'));
        $frame2 = new Frame(BitManipulation::hexArrayToString('80', '02', '6c', '6f'));

        $framefactory = $this->prophesize(FrameFactory::class);
        $processor = new MessageProcessor($framefactory->reveal());

        $expectedMessage = new Message();
        $expectedMessage->addFrame($frame1);
        $expectedMessage->addFrame($frame2);

        $messages = iterator_to_array($processor->onData(
            $multipleFrameData,
            $this->socket->reveal()
        ));

        $this->assertSame($messages[0]->getContent(), 'ping payload');
        $this->assertSame($messages[1]->getContent(), 'fragment1fragment2');
        $this->assertTrue($messages[0]->isComplete());
        $this->assertTrue($messages[1]->isComplete());
        $this->assertSame($messages[0]->isComplete(), $expectedMessage->isComplete());
        $this->assertSame($messages[1]->isComplete(), $expectedMessage->isComplete());
        $this->assertSame($messages[0]->getOpcode(), Frame::OP_PING);
        $this->assertSame($messages[1]->getOpcode(), Frame::OP_TEXT);
        $this->assertSame(count($messages[0]->getFrames()), 1);
        $this->assertSame(count($messages[1]->getFrames()), 2);
    }
}
