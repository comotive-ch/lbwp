class OrderCompletion{
  maxImageSize = {
    width: 800,
    height: 600
  }

  imageQuality = 0.6;

  constructor() {
    this.handleAbsenceImageUpload();
  }

  handleAbsenceImageUpload(){
    let uploadField = document.getElementById('absence-image');
    let uploadField2 = document.getElementById('absence-image-2');

    if(uploadField != null){
      uploadField.addEventListener('change', (event)=>{this.compressImage(event);});
    }
    if(uploadField2 != null){
      uploadField2.addEventListener('change', (event)=>{this.compressImage(event);});
    }
  }

  compressImage(event){
    let self = this;
    let file = event.target.files[0];
    let blobUrl = window.URL.createObjectURL(file);
    let img = new Image();
    let imageId = event.target.id;

    img.src = blobUrl;
    img.onload = function(e){
      window.URL.revokeObjectURL(blobUrl);
      const canvas = document.createElement('canvas');
      let imgSize = self.getImageSize(img);

      canvas.width = imgSize.width;
      canvas.height = imgSize.height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, imgSize.width, imgSize.height);
      canvas.toBlob((blob) => {
        let reader = new FileReader();
        reader.readAsDataURL(blob);
        reader.onloadend = function () {
          let base64String = reader.result;
          document.querySelector('input[name="' + imageId + '"]').value = base64String;
        };
      }, "image/jpeg", self.imageQuality)
    }
  }

  getImageSize(image){
    let imageSize = {width: image.width, height: image.height};

    if(image.width > this.maxImageSize.width){
      imageSize.width = this.maxImageSize.width;
      imageSize.height = image.height * (this.maxImageSize.width / image.width);
    }else if(image.height > this.maxImageSize.height){
      imageSize.height = this.maxImageSize.height;
      imageSize.width = image.width * (this.maxImageSize.height / image.height);
    }

    return imageSize;
  }
}

document.addEventListener('readystatechange', (event) => {
  if (document.readyState === "complete") {
    new OrderCompletion();
  }
});