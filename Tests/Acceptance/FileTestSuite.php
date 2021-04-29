<?php

namespace Visol\GoogleCloudStorage\Tests\Acceptance;


use Visol\GoogleCloudStorage\Tests\Acceptance\FileOperation\AddFileOperationTest;
use Visol\GoogleCloudStorage\Tests\Acceptance\FileOperation\CopyFileOperationTest;
use Visol\GoogleCloudStorage\Tests\Acceptance\FileOperation\DeleteFileOperationTest;
use Visol\GoogleCloudStorage\Tests\Acceptance\FileOperation\DeleteFolderOperationTest;
use Visol\GoogleCloudStorage\Tests\Acceptance\FileOperation\MoveFileOperationTest;
use Visol\GoogleCloudStorage\Tests\Acceptance\FileOperation\ReadFileOperationTest;

class FileTestSuite extends AbstractCloudinaryTestSuite
{

    /**
     * @var
     */
    protected $files = [
        'sub-folder/image-jpeg.jpeg',
        'sub-folder/image-tiff.tiff',
        'image-jpg.jpg',
        'image-png.png',
        'document.odt',
        'document.pdf',
        'video.youtube',
        'video.mp4',
    ];

    public function runTests()
    {

        // Basic access file such as read, write, delete
        foreach ($this->files as $fileNameAndPath) {

            $test = new AddFileOperationTest($this, $fileNameAndPath);
            $test->run();

            $test = new ReadFileOperationTest($this, $fileNameAndPath);
            $test->run();

            $copyFileName = $this->getAlternativeName($fileNameAndPath, 'copied');
            $test = new CopyFileOperationTest($this, $fileNameAndPath);
            $test->setTargetFileName($copyFileName)
                ->run();

            $moveFileName = $this->getAlternativeName($fileNameAndPath, 'moved');
            $test = new MoveFileOperationTest($this, $copyFileName);
            $test->setTargetFileName($moveFileName)
                ->run();

            $test = new DeleteFileOperationTest($this, $fileNameAndPath);
            $test->run();
        }

        $test = new DeleteFolderOperationTest($this);
        $test->run();
    }
}
