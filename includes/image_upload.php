<?php

class ImageUploadHandler {
    private $uploadDir;
    private $maxFileSize;
    private $allowedTypes;
    
    public function __construct() {
        $this->uploadDir = __DIR__ . '/../uploads/chat_images/';
        $this->maxFileSize = 10 * 1024 * 1024; // 10MB
        $this->allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        
        // Ensure upload directory exists
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        
        // Create .htaccess file for security
        $htaccessFile = $this->uploadDir . '.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, "# Deny direct access to uploaded files\nOptions -Indexes\n");
        }
    }
    
    public function handleUpload($file) {
        try {
            // Validate file upload
            if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload failed: ' . $this->getUploadErrorMessage($file['error']));
            }
            
            // Check file size
            if ($file['size'] > $this->maxFileSize) {
                throw new Exception('File too large. Maximum size: ' . ($this->maxFileSize / 1024 / 1024) . 'MB');
            }
            
            // Validate file type
            $mimeType = $this->getMimeType($file['tmp_name']);
            if (!array_key_exists($mimeType, $this->allowedTypes)) {
                throw new Exception('Unsupported file type. Allowed types: JPEG, PNG, GIF, WebP');
            }
            
            // Validate that it's actually an image
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                throw new Exception('Invalid image file');
            }
            
            // Generate secure filename
            $extension = $this->allowedTypes[$mimeType];
            $filename = $this->generateSecureFilename($extension);
            $filepath = $this->uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('Failed to save uploaded file');
            }
            
            // Optimize image (optional - resize if too large)
            $this->optimizeImage($filepath, $mimeType);
            
            return [
                'success' => true,
                'filename' => $filename,
                'original_name' => sanitizeInput($file['name']),
                'mime_type' => $mimeType,
                'size' => $file['size'],
                'width' => $imageInfo[0],
                'height' => $imageInfo[1]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function getMimeType($filepath) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filepath);
        finfo_close($finfo);
        return $mimeType;
    }
    
    private function generateSecureFilename($extension) {
        return bin2hex(random_bytes(16)) . '.' . $extension;
    }
    
    private function getUploadErrorMessage($error) {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
                return 'File exceeds maximum allowed size';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds form maximum size';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }
    
    private function optimizeImage($filepath, $mimeType) {
        // Get current image dimensions
        $imageInfo = getimagesize($filepath);
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        // Only resize if image is very large (> 2000px in any dimension)
        $maxDimension = 2000;
        if ($width <= $maxDimension && $height <= $maxDimension) {
            return;
        }
        
        // Calculate new dimensions maintaining aspect ratio
        if ($width > $height) {
            $newWidth = $maxDimension;
            $newHeight = intval(($height * $maxDimension) / $width);
        } else {
            $newHeight = $maxDimension;
            $newWidth = intval(($width * $maxDimension) / $height);
        }
        
        // Create image resource based on type
        switch ($mimeType) {
            case 'image/jpeg':
                $sourceImage = imagecreatefromjpeg($filepath);
                break;
            case 'image/png':
                $sourceImage = imagecreatefrompng($filepath);
                break;
            case 'image/gif':
                $sourceImage = imagecreatefromgif($filepath);
                break;
            case 'image/webp':
                $sourceImage = imagecreatefromwebp($filepath);
                break;
            default:
                return; // Unsupported type, skip optimization
        }
        
        if (!$sourceImage) {
            return; // Failed to create image resource
        }
        
        // Create new image with optimized dimensions
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG and GIF
        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefill($newImage, 0, 0, $transparent);
        }
        
        // Resize image
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Save optimized image
        switch ($mimeType) {
            case 'image/jpeg':
                imagejpeg($newImage, $filepath, 85); // 85% quality
                break;
            case 'image/png':
                imagepng($newImage, $filepath, 8); // Compression level 8
                break;
            case 'image/gif':
                imagegif($newImage, $filepath);
                break;
            case 'image/webp':
                imagewebp($newImage, $filepath, 85); // 85% quality
                break;
        }
        
        // Clean up memory
        imagedestroy($sourceImage);
        imagedestroy($newImage);
    }
    
    public function getImagePath($filename) {
        return $this->uploadDir . $filename;
    }
    
    public function getImageUrl($filename) {
        return '/uploads/chat_images/' . $filename;
    }
    
    public function deleteImage($filename) {
        $filepath = $this->uploadDir . $filename;
        if (file_exists($filepath)) {
            return unlink($filepath);
        }
        return false;
    }
    
    public function imageExists($filename) {
        return file_exists($this->uploadDir . $filename);
    }
}

// Helper function to convert image to base64 for Anthropic API
function imageToBase64($filepath) {
    if (!file_exists($filepath)) {
        throw new Exception('Image file not found');
    }
    
    $imageData = file_get_contents($filepath);
    if ($imageData === false) {
        throw new Exception('Failed to read image file');
    }
    
    $mimeType = mime_content_type($filepath);
    return [
        'data' => base64_encode($imageData),
        'mime_type' => $mimeType
    ];
}

?>